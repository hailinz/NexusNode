<?php

namespace App\Services;

use App\Models\Node;
use RuntimeException;

/**
 * 批量导入服务：多行文本 / 上传文件 → 节点
 * 自动识别整体 base64 的订阅内容；逐行解析并按业务键去重
 */
class ImportService
{
    /** 最多展示的错误行数 */
    private const MAX_ERRORS = 20;

    public function __construct(private readonly ProxyUriParser $parser)
    {
    }

    /**
     * @return array{created:int, skipped:int, failed:int, errors:string[], total:int}
     */
    public function importText(string $text): array
    {
        $text = $this->unwrapBase64Subscription($text);

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $stats = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'total' => 0];

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
                continue;
            }

            $stats['total']++;
            $lineNo = $index + 1;

            try {
                $data = $this->parser->parse($line);
            } catch (RuntimeException $e) {
                $stats['failed']++;
                if (count($stats['errors']) < self::MAX_ERRORS) {
                    $stats['errors'][] = "第 {$lineNo} 行：{$e->getMessage()}";
                }
                continue;
            }

            if ($this->duplicateExists($data)) {
                $stats['skipped']++;
                continue;
            }

            Node::create($data);
            $stats['created']++;
        }

        return $stats;
    }

    /**
     * 内容不含 "://" 时尝试按 base64 订阅整体解码
     */
    private function unwrapBase64Subscription(string $text): string
    {
        $trimmed = trim($text);
        if ($trimmed === '' || str_contains($trimmed, '://') || str_contains($trimmed, "\n")) {
            return $text;
        }

        $decoded = Base64Helper::decodeFlexible($trimmed);
        if (is_string($decoded) && str_contains($decoded, '://')) {
            return $decoded;
        }

        return $text;
    }

    /**
     * 业务键判重：协议+凭据+地址+端口+path
     */
    private function duplicateExists(array $data): bool
    {
        $query = Node::query();

        foreach (Node::duplicateMatchAttributes() as $field) {
            $value = $data[$field] ?? null;
            $value === null
                ? $query->whereNull($field)
                : $query->where($field, $value);
        }

        return $query->exists();
    }
}
