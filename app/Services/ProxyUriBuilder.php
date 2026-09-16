<?php

namespace App\Services;

use App\Models\Node;

/**
 * 代理链接生成器：由数据库中的结构化字段重建完整代理链接
 */
class ProxyUriBuilder
{
    /**
     * 将节点（模型或数组）重建为完整代理链接
     */
    public static function build(Node|array $node): string
    {
        // 模型经 attributesToArray() 输出：extras 已按 cast 转为数组
        $n = $node instanceof Node ? $node->attributesToArray() : $node;

        return match ($n['protocol']) {
            'vmess' => self::buildVmess($n),
            'ss' => self::buildSs($n),
            'trojan' => self::buildGeneric('trojan', $n, includeEncryption: false),
            default => self::buildGeneric('vless', $n, includeEncryption: true),
        };
    }

    /**
     * vless://uuid@host:port?params#name 与 trojan://password@host:port?params#name
     */
    private static function buildGeneric(string $scheme, array $n, bool $includeEncryption): string
    {
        $params = [];

        if ($includeEncryption) {
            self::pushParam($params, 'encryption', $n['encryption'] ?? null);
        }
        self::pushParam($params, 'security', $n['security'] ?? null);
        self::pushParam($params, 'sni', $n['sni'] ?? null);
        self::pushParam($params, 'fp', $n['fingerprint'] ?? null);
        self::pushParam($params, 'alpn', $n['alpn'] ?? null);
        self::pushParam($params, 'type', $n['network'] ?? null);
        self::pushParam($params, 'host', $n['host'] ?? null);
        self::pushParam($params, 'path', $n['path'] ?? null);
        self::pushParam($params, 'flow', $n['flow'] ?? null);

        // 还原未知参数（pcs、insecure、pbk、sid 等）
        foreach (($n['extras'] ?? []) as $key => $value) {
            self::pushParam($params, (string) $key, (string) $value);
        }

        $uri = sprintf('%s://%s@%s:%s', $scheme, rawurlencode($n['uuid'] ?? ''), $n['address'], $n['port']);
        if ($params !== []) {
            $uri .= '?' . implode('&', $params);
        }

        $name = $n['name'] ?? '';
        if ($name !== '') {
            $uri .= '#' . rawurlencode($name);
        }

        return $uri;
    }

    /**
     * vmess://base64(json)
     */
    private static function buildVmess(array $n): string
    {
        $extras = $n['extras'] ?? [];

        $json = array_filter([
            'v' => '2',
            'ps' => $n['name'] ?? '',
            'add' => (string) $n['address'],
            'port' => (string) $n['port'],
            'id' => (string) ($n['uuid'] ?? ''),
            'aid' => (string) ($extras['aid'] ?? '0'),
            'scy' => $n['encryption'] ?? 'auto',
            'net' => $n['network'] ?? 'tcp',
            'type' => $extras['type'] ?? 'none',
            'host' => $n['host'] ?? '',
            'path' => $n['path'] ?? '',
            'tls' => ($n['security'] ?? null) === 'tls' ? 'tls' : '',
            'sni' => $n['sni'] ?? '',
            'alpn' => $n['alpn'] ?? '',
            'fp' => $n['fingerprint'] ?? '',
        ], fn ($v) => $v !== '');

        return 'vmess://' . base64_encode(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * ss://base64(method:password@host:port)#name （旧版整体编码，兼容性最好）
     */
    private static function buildSs(array $n): string
    {
        $plain = sprintf('%s:%s@%s:%s', $n['encryption'] ?? 'aes-256-gcm', $n['uuid'] ?? '', $n['address'], $n['port']);
        $uri = 'ss://' . base64_encode($plain);

        $name = $n['name'] ?? '';
        if ($name !== '') {
            $uri .= '#' . rawurlencode($name);
        }

        return $uri;
    }

    /**
     * 追加参数：空值跳过
     */
    private static function pushParam(array &$params, string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $params[] = $key . '=' . rawurlencode($value);
    }
}
