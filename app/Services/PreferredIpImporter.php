<?php

namespace App\Services;

use App\Models\PreferredIp;

/**
 * CF 优选 IP 导入器
 *
 * 支持两种输入：
 * 1. 纯文本：每行一个 IP，可选备注，如 "1.2.3.4#香港" / "1.2.3.4 香港" / "1.2.3.4,HK" / "1.2.3.4"
 * 2. CloudflareSpeedTest 的 result.csv：IP 地址,已发送,已接收,丢包率,平均延迟,下载速度 (MB/s)
 */
class PreferredIpImporter
{
    /**
     * @return array{created:int, updated:int, failed:int, errors:string[]}
     */
    public function importText(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $stats = ['created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
                continue;
            }

            // 提取备注：#、空格或逗号之后的部分
            $remark = null;
            if (preg_match('/[,\s#]/', $line, $m, PREG_OFFSET_CAPTURE)) {
                $remark = trim(substr($line, $m[0][1] + 1)) ?: null;
                $line = substr($line, 0, $m[0][1]);
            }
            // 兼容 "1.2.3.4:443" 写法，端口不属于 IP 本身
            $line = rtrim($line);

            if (!filter_var($line, FILTER_VALIDATE_IP)) {
                $stats['failed']++;
                if (count($stats['errors']) < 20) {
                    $stats['errors'][] = '第 ' . ($index + 1) . ' 行：非法 IP「' . mb_substr($line, 0, 40) . '」';
                }
                continue;
            }

            $this->upsertIp($line, $remark, $stats);
        }

        return $stats;
    }

    /**
     * 导入 CloudflareSpeedTest 的 result.csv
     *
     * @return array{created:int, updated:int, failed:int, errors:string[]}
     */
    public function importCsv(string $content): array
    {
        // CloudflareSpeedTest 在 Windows 下可能输出 GBK 编码
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'GBK');
        }

        $rows = array_values(array_filter(
            array_map('trim', explode("\n", $content)),
            fn ($r) => $r !== ''
        ));

        $stats = ['created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
        if ($rows === []) {
            $stats['errors'][] = 'CSV 文件为空';
            return $stats;
        }

        // 首行是表头则按列名映射，否则按 CloudflareSpeedTest 默认列序
        $first = str_getcsv($rows[0]);
        if ($first !== false && !filter_var($this->cleanIpCell($first[0]), FILTER_VALIDATE_IP)) {
            array_shift($rows);
            $map = $this->buildColumnMap($first);
        } else {
            $map = ['ip' => 0, 'loss_rate' => 3, 'latency_ms' => 4, 'download_speed' => 5];
        }

        foreach ($rows as $index => $row) {
            $cells = str_getcsv($row);
            if ($cells === false || count($cells) < 2) {
                continue;
            }

            $ip = $this->cleanIpCell($cells[$map['ip']] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $stats['failed']++;
                if (count($stats['errors']) < 20) {
                    $stats['errors'][] = '第 ' . ($index + 1) . ' 行：非法 IP「' . mb_substr($ip, 0, 40) . '」';
                }
                continue;
            }

            $this->upsertIp($ip, null, $stats, [
                'loss_rate' => isset($map['loss_rate']) ? $this->toFloat($cells[$map['loss_rate']] ?? null) : null,
                'latency_ms' => isset($map['latency_ms']) ? $this->toFloat($cells[$map['latency_ms']] ?? null) : null,
                'download_speed' => isset($map['download_speed']) ? $this->toFloat($cells[$map['download_speed']] ?? null) : null,
            ]);
        }

        return $stats;
    }

    /**
     * 按 IP 唯一键 upsert，metrics 传 null 的字段保持原值
     */
    private function upsertIp(string $ip, ?string $remark, array &$stats, array $metrics = []): void
    {
        $existing = PreferredIp::where('ip', $ip)->first();

        $payload = array_filter([
            'remarks' => $remark,
            'loss_rate' => $metrics['loss_rate'] ?? null,
            'latency_ms' => $metrics['latency_ms'] ?? null,
            'download_speed' => $metrics['download_speed'] ?? null,
        ], fn ($v) => $v !== null);

        if ($existing) {
            $existing->fill($payload)->save();
            $stats['updated']++;
        } else {
            PreferredIp::create(['ip' => $ip, 'enabled' => true] + $payload);
            $stats['created']++;
        }
    }

    /**
     * 根据表头文本定位列索引
     */
    private function buildColumnMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $title) {
            $title = (string) $title;
            if (str_contains($title, 'IP') && !isset($map['ip'])) {
                $map['ip'] = $i;
            } elseif (str_contains($title, '丢包')) {
                $map['loss_rate'] = $i;
            } elseif (str_contains($title, '延迟')) {
                $map['latency_ms'] = $i;
            } elseif (str_contains($title, '速度')) {
                $map['download_speed'] = $i;
            }
        }

        return $map + ['ip' => 0];
    }

    /**
     * 单元格清洗：去除端口、引号与空白
     */
    private function cleanIpCell(string $cell): string
    {
        $cell = trim($cell, " \t\"'");
        $colon = strpos($cell, ':');
        if ($colon !== false && !str_contains($cell, '::')) {
            $cell = substr($cell, 0, $colon);
        }

        return trim($cell);
    }

    /**
     * 数字字段容错转换
     */
    private function toFloat(?string $value): ?float
    {
        if ($value === null || trim($value) === '' || !is_numeric(trim($value))) {
            return null;
        }

        return (float) trim($value);
    }
}
