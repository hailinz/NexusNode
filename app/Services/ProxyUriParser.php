<?php

namespace App\Services;

use App\Models\Node;
use RuntimeException;

/**
 * 代理链接解析器：vless / vmess / trojan / ss
 *
 * 设计要点：
 * - 未知查询参数（如 pcs、insecure、pbk）保存到 extras，保证订阅回写时不丢失信息
 * - 解析失败的行抛出 RuntimeException，由导入服务汇总展示
 */
class ProxyUriParser
{
    /**
     * 有独立数据库字段的查询参数，其余参数全部进入 extras
     */
    private const KNOWN_PARAMS = [
        'encryption', 'security', 'sni', 'fp', 'type', 'host', 'path', 'flow', 'alpn',
    ];

    /**
     * 解析单行代理链接，返回可用于 Node::create 的数组
     *
     * @throws RuntimeException 当链接格式非法或协议不支持时
     */
    public function parse(string $uri): array
    {
        $uri = trim($uri);

        $scheme = strtolower(strtok($uri, ':') ?: '');
        $rest = substr($uri, strlen($scheme) + 3);

        return match ($scheme) {
            'vless', 'trojan' => $this->parseGeneric($uri, $scheme),
            'vmess' => $this->parseVmess($rest),
            'ss' => $this->parseShadowsocks($rest),
            default => throw new RuntimeException("不支持的协议: {$scheme}"),
        };
    }

    /**
     * 解析 vless:// 和 trojan:// —— 两者查询参数结构一致
     */
    private function parseGeneric(string $uri, string $protocol): array
    {
        $parts = parse_url($uri);
        if ($parts === false || empty($parts['host']) || !isset($parts['port'])) {
            throw new RuntimeException('链接缺少主机或端口');
        }

        $uuid = rawurldecode($parts['user'] ?? '');
        if ($uuid === '') {
            throw new RuntimeException('链接缺少用户凭据（UUID/密码）');
        }

        $params = $this->parseQuery($parts['query'] ?? '');

        $known = [];
        $known['encryption'] = $params['encryption'] ?? null;
        $known['security'] = $params['security'] ?? null;
        $known['sni'] = $params['sni'] ?? null;
        $known['fingerprint'] = $params['fp'] ?? null;
        $known['alpn'] = $params['alpn'] ?? null;
        $known['network'] = $params['type'] ?? 'tcp';
        $known['host'] = $params['host'] ?? null;
        $known['path'] = $params['path'] ?? null;
        $known['flow'] = $params['flow'] ?? null;

        // 其余参数原样保留（pcs、insecure、pbk、sid、serviceName 等）
        $extras = array_diff_key($params, array_flip(self::KNOWN_PARAMS));

        return [
            'name' => isset($parts['fragment']) && $parts['fragment'] !== ''
                ? rawurldecode($parts['fragment'])
                : $parts['host'] . ':' . $parts['port'],
            'protocol' => $protocol,
            'uuid' => $uuid,
            'address' => $parts['host'],
            'port' => $parts['port'],
            'security' => $known['security'],
            'sni' => $known['sni'],
            'host' => $known['host'],
            'path' => $known['path'],
            'network' => $known['network'],
            'flow' => $known['flow'],
            'fingerprint' => $known['fingerprint'],
            'encryption' => $known['encryption'],
            'alpn' => $known['alpn'],
            'extras' => $extras ?: null,
        ];
    }

    /**
     * 解析 vmess://（base64 编码的 JSON）
     */
    private function parseVmess(string $payload): array
    {
        $json = Base64Helper::decodeFlexible($payload);
        if ($json === false) {
            throw new RuntimeException('vmess 链接 base64 解码失败');
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['add']) || empty($data['port']) || empty($data['id'])) {
            throw new RuntimeException('vmess JSON 缺少必要字段（add/port/id）');
        }

        $extras = [];
        if (isset($data['aid']) && $data['aid'] !== '0' && $data['aid'] !== 0) {
            $extras['aid'] = (string) $data['aid'];
        }
        if (isset($data['type']) && $data['type'] !== 'none') {
            $extras['type'] = (string) $data['type'];
        }

        return [
            'name' => $data['ps'] ?? ($data['add'] . ':' . $data['port']),
            'protocol' => 'vmess',
            'uuid' => (string) $data['id'],
            'address' => (string) $data['add'],
            'port' => (int) $data['port'],
            'security' => (($data['tls'] ?? '') === 'tls') ? 'tls' : null,
            'sni' => $data['sni'] ?? null,
            'host' => $data['host'] ?? null,
            'path' => $data['path'] ?? null,
            'network' => $data['net'] ?? 'tcp',
            'flow' => $data['flow'] ?? null,
            'fingerprint' => $data['fp'] ?? null,
            'encryption' => $data['scy'] ?? 'auto',
            'alpn' => $data['alpn'] ?? null,
            'extras' => $extras ?: null,
        ];
    }

    /**
     * 解析 ss:// —— 支持 SIP002（base64 userinfo@host:port）与旧版整体 base64 两种格式
     */
    private function parseShadowsocks(string $body): array
    {
        // 拆出节点名称
        $hashPos = strpos($body, '#');
        $name = null;
        if ($hashPos !== false) {
            $name = rawurldecode(substr($body, $hashPos + 1));
            $body = substr($body, 0, $hashPos);
        }
        $body = rawurldecode($body);

        $extras = [];
        // 查询参数（如 plugin=...）
        $queryPos = strpos($body, '?');
        if ($queryPos !== false) {
            $extras = $this->parseQuery(substr($body, $queryPos + 1));
            $body = substr($body, 0, $queryPos);
        }

        if (str_contains($body, '@')) {
            // SIP002: base64(method:password)@host:port
            $atPos = strrpos($body, '@');
            $userinfo = Base64Helper::decodeFlexible(substr($body, 0, $atPos));
            if ($userinfo === false || !str_contains($userinfo, ':')) {
                throw new RuntimeException('ss userinfo 解码失败');
            }
            $hostport = substr($body, $atPos + 1);
        } else {
            // 旧版: base64(method:password@host:port)
            $decoded = Base64Helper::decodeFlexible($body);
            if ($decoded === false || !str_contains($decoded, '@')) {
                throw new RuntimeException('ss 链接 base64 解码失败');
            }
            $atPos = strrpos($decoded, '@');
            $userinfo = substr($decoded, 0, $atPos);
            $hostport = substr($decoded, $atPos + 1);
        }

        [$method, $password] = explode(':', $userinfo, 2);

        $colonPos = strrpos($hostport, ':');
        if ($colonPos === false) {
            throw new RuntimeException('ss 链接缺少端口');
        }
        $address = substr($hostport, 0, $colonPos);
        $port = (int) substr($hostport, $colonPos + 1);
        // 兼容 IPv6 字面量 [::1]:443
        if (str_starts_with($address, '[') && str_ends_with($address, ']')) {
            $address = substr($address, 1, -1);
        }

        if ($address === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('ss 链接地址或端口非法');
        }

        return [
            'name' => $name ?: ($address . ':' . $port),
            'protocol' => 'ss',
            'uuid' => $password,
            'address' => $address,
            'port' => $port,
            'security' => null,
            'sni' => null,
            'host' => null,
            'path' => null,
            'network' => 'tcp',
            'flow' => null,
            'fingerprint' => null,
            'encryption' => $method,
            'alpn' => null,
            'extras' => $extras ?: null,
        ];
    }

    /**
     * 手工拆分查询字符串：保留参数顺序，不改动参数名（parse_str 会把参数名中的点转为下划线）
     */
    private function parseQuery(string $query): array
    {
        $params = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[rawurldecode($key)] = rawurldecode($value);
        }

        return $params;
    }
}
