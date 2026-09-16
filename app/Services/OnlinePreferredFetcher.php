<?php

namespace App\Services;

use App\Models\PreferredIp;
use App\Models\PreferredIpSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 在线优选 API 拉取器
 *
 * 移植自 edgetunnel(work.js) 的 请求优选API() 函数：
 * - 支持 纯文本行（IP#备注 / IP:端口#备注）、CSV 地区版（IP地址,端口,数据中心）、
 *   CSV 测速版（IP,平均延迟,下载速度 —— CloudflareSpeedTest result.csv 在线版）
 * - 自动识别整体 base64 内容（订阅链接内容跳过）
 * - 编码容错（UTF-8 / GBK）
 * - IPv6 自动加方括号；API 返回的非 443 端口写入备注供参考
 */
class OnlinePreferredFetcher
{
    /** 单源拉取超时（秒） */
    private const TIMEOUT = 6;

    /**
     * 内置在线 IP 库（与 edgetunnel cf.html 的 IP_LIBRARIES 一致，供浏览器实时优选）
     */
    public const POOLS = [
        'cf-v4' => ['name' => 'CF官方列表v4', 'url' => 'https://cf.090227.xyz/ips-v4'],
        'cf-v6' => ['name' => 'CF官方列表v6', 'url' => 'https://cf.090227.xyz/ips-v6'],
        'cm-v4' => ['name' => 'CM优选列表v4', 'url' => 'https://raw.githubusercontent.com/cmliu/cmliu/main/CF-CIDR.txt'],
        'as13335-v4' => ['name' => 'AS13335列表v4', 'url' => 'https://raw.githubusercontent.com/ipverse/asn-ip/master/as/13335/ipv4-aggregated.txt'],
        'as13335-v6' => ['name' => 'AS13335列表v6', 'url' => 'https://raw.githubusercontent.com/ipverse/asn-ip/master/as/13335/ipv6-aggregated.txt'],
        'as209242-v4' => ['name' => 'AS209242列表v4', 'url' => 'https://raw.githubusercontent.com/ipverse/asn-ip/master/as/209242/ipv4-aggregated.txt'],
        'as209242-v6' => ['name' => 'AS209242列表v6', 'url' => 'https://raw.githubusercontent.com/ipverse/asn-ip/master/as/209242/ipv6-aggregated.txt'],
    ];

    /**
     * 拉取内置 IP 库的原始候选清单（10 分钟缓存，避免重复外网请求）
     *
     * 与 cf.html（BestCF）在线优选一致：返回 IP / CIDR / IP 区间的原始行，
     * 由浏览器在「IP 库导入 → 优选」时随机展开 CIDR/区间取样，而不是服务端归一为单 IP。
     *
     * @return string 换行分隔的候选清单
     */
    public function fetchPool(string $key): string
    {
        if (!isset(self::POOLS[$key])) {
            throw new \InvalidArgumentException("未知的 IP 库: {$key}");
        }

        // 缓存键带 raw 标记：旧版缓存的是归一化 IP 数组，避免读到过期格式
        return Cache::remember("online-pool-raw:{$key}", 600, function () use ($key) {
            $url = self::POOLS[$key]['url'];
            $response = Http::timeout(self::TIMEOUT)
                ->withOptions(['verify' => $this->caBundle()])
                ->withHeaders(['User-Agent' => 'NexusNode/1.0'])
                ->get($url);

            if (!$response->successful()) {
                throw new \RuntimeException("IP 库拉取失败：HTTP {$response->status()}");
            }

            $text = $this->decodeBody($response->body());

            // 保留原始行（IP/CIDR/区间），剔除空行、注释行与疑似 HTML 错误页
            $lines = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []),
                fn (string $line): bool => $line !== '' && !str_starts_with($line, '#') && !str_starts_with($line, '<')
            ));

            return implode("\n", $lines);
        });
    }

    /**
     * 拉取 BestCF 探测域名的 locations 数据（IATA → 机房国家/城市）
     *
     * 地理信息几乎不变，服务端缓存 1 天；两个探测域名依次尝试。
     * 子域标签固定：locations 端点不校验标签内容，用固定值可让操作系统 DNS 缓存持续命中
     * （随机标签每次都要重走泛解析上游，弱 DNS 环境下会解析超时）。
     *
     * @return array<int, array{iata:string, cca2:string, city?:string, region?:string, ...}>
     */
    public function fetchLocations(): array
    {
        return Cache::remember('online-locations', 86400, function (): array {
            $lastError = null;
            foreach (['bestcf.cmliussss.hidns.vip', 'ns.psb.kdns.fr'] as $host) {
                $url = "https://681001AB.{$host}/locations?_t=" . time();
                try {
                    $response = Http::timeout(15)
                        ->withOptions(['verify' => $this->caBundle()])
                        ->withHeaders(['User-Agent' => 'NexusNode/1.0'])
                        ->get($url);

                    if (!$response->successful()) {
                        $lastError = "HTTP {$response->status()}";
                        continue;
                    }
                    $json = json_decode($response->body(), true);
                    if (!is_array($json) || !isset($json[0]['iata'])) {
                        $lastError = 'locations 响应格式无效';
                        continue;
                    }

                    return $json;
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                }
            }

            throw new \RuntimeException("locations 拉取失败：{$lastError}");
        });
    }

    /**
     * TLS 校验用的 CA 证书包路径
     * 优先级：php.ini 配置 → 项目内置证书包（resources/certs/cacert.pem，随代码分发，免环境配置）→ 系统默认
     */
    private function caBundle(): string|bool
    {
        $ini = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if (is_string($ini) && $ini !== '' && is_file($ini)) {
            return $ini;
        }

        $bundled = base_path('resources/certs/cacert.pem');
        if (is_file($bundled)) {
            return $bundled;
        }

        return true;
    }

    /**
     * 同步单个源，返回入库 IP 数
     */
    public function syncSource(PreferredIpSource $source): int
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withOptions(['verify' => $this->caBundle()])
                ->withHeaders(['User-Agent' => 'NexusNode/1.0'])
                ->get($source->url);

            if (!$response->successful()) {
                throw new \RuntimeException('HTTP ' . $response->status());
            }

            $text = $this->decodeBody($response->body());
            if (trim($text) === '') {
                throw new \RuntimeException('响应内容为空');
            }

            $entries = $this->parseContent($text, $source->name);
            if ($entries === []) {
                throw new \RuntimeException('未解析出有效 IP');
            }

            foreach ($entries as $entry) {
                $this->upsertIp($entry);
            }

            $source->update([
                'last_synced_at' => now(),
                'last_count' => count($entries),
                'last_error' => null,
            ]);

            return count($entries);
        } catch (\Throwable $e) {
            $source->update([
                'last_synced_at' => now(),
                'last_error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return 0;
        }
    }

    /**
     * 同步全部启用的源
     *
     * @return array{synced:int, failed:int, total:int}
     */
    public function syncAll(): array
    {
        $stats = ['synced' => 0, 'failed' => 0, 'total' => 0];

        foreach (PreferredIpSource::query()->enabled()->get() as $source) {
            $count = $this->syncSource($source);
            $count > 0 ? $stats['synced']++ : $stats['failed']++;
            $stats['total'] += $count;
        }

        return $stats;
    }

    /**
     * 解析 API 响应文本为 IP 条目列表（公开便于测试）
     *
     * @return array<int, array{ip:string, remark:?string, latency_ms:?float, download_speed:?float}>
     */
    public function parseContent(string $text, string $fallbackRemark): array
    {
        // 整体 base64 内容自动解码（解码后是节点订阅链接则跳过——只关心 IP）
        $trimmed = trim($text);
        if (!str_contains($trimmed, "\n")) {
            $decoded = Base64Helper::decodeFlexible($trimmed);
            if (is_string($decoded)) {
                if (str_contains($decoded, '://')) {
                    return [];
                }
                $text = $decoded;
            }
        }

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            fn ($line) => $line !== ''
        ));

        if ($lines === []) {
            return [];
        }

        // CSV 判定与 work.js 一致：多行且首行含逗号
        $isCsv = count($lines) > 1 && str_contains($lines[0], ',');
        if ($isCsv) {
            return $this->parseCsv($lines);
        }

        return $this->parsePlainText($lines, $fallbackRemark);
    }

    /**
     * 纯文本行解析：IP#备注 / IP:端口#备注 / IPv6
     */
    private function parsePlainText(array $lines, string $fallbackRemark): array
    {
        $entries = [];

        foreach ($lines as $line) {
            // 逗号分隔的 IP 列表（部分 API 单行输出 a,b,c）
            if (str_contains($line, ',')) {
                foreach (explode(',', $line) as $segment) {
                    $segRemark = null;
                    if (str_contains($segment, '#')) {
                        [$segment, $segRemark] = explode('#', $segment, 2);
                        $segRemark = trim(rawurldecode($segRemark));
                    }
                    $ip = $this->normalizeIp($segment);
                    if ($ip !== null) {
                        $entries[] = [
                            'ip' => $ip,
                            'remark' => $this->buildRemark($segRemark ?: null, $fallbackRemark, null),
                            'latency_ms' => null,
                            'download_speed' => null,
                        ];
                    }
                }
                continue;
            }

            // 拆备注
            $hashPos = strpos($line, '#');
            $remark = null;
            if ($hashPos !== false) {
                $remark = trim(rawurldecode(substr($line, $hashPos + 1)));
                $line = substr($line, 0, $hashPos);
            }

            // 拆端口：[IPv6]:443 与 1.2.3.4:2053 两种形态；域名行跳过
            $port = null;
            if (preg_match('/^\[(.+)\]:(\d+)$/', $line, $m)) {
                $line = $m[1];
                $port = (int) $m[2];
            } elseif (!str_contains($line, '::') && preg_match('/^(.+?):(\d+)$/', $line, $m)) {
                if (!filter_var($m[1], FILTER_VALIDATE_IP)) {
                    continue;
                }
                $line = $m[1];
                $port = (int) $m[2];
            }

            $ip = $this->normalizeIp($line);
            if ($ip === null) {
                continue;
            }

            $entries[] = [
                'ip' => $ip,
                'remark' => $this->buildRemark($remark, $fallbackRemark, $port),
                'latency_ms' => null,
                'download_speed' => null,
            ];
        }

        return $entries;
    }

    /**
     * CSV 解析：地区版（IP地址,端口,数据中心）与测速版（IP,延迟,下载速度）
     */
    private function parseCsv(array $lines): array
    {
        $headers = array_map('trim', str_getcsv($lines[0]));
        $dataLines = array_slice($lines, 1);

        $find = function (array $keywords) use ($headers): ?int {
            foreach ($headers as $i => $title) {
                foreach ($keywords as $keyword) {
                    if (str_contains($title, $keyword)) {
                        return $i;
                    }
                }
            }

            return null;
        };

        $ipIdx = $find(['IP']);
        if ($ipIdx === null) {
            return [];
        }

        // 测速版：IP + 延迟 + 下载速度（CloudflareSpeedTest result.csv）
        $latencyIdx = $find(['延迟']);
        $speedIdx = $find(['速度']);
        if ($latencyIdx !== null && $speedIdx !== null) {
            return $this->parseSpeedTestCsv($dataLines, $ipIdx, $latencyIdx, $speedIdx);
        }

        // 地区版：IP + 端口 + 地区（数据中心/国家/城市），TLS 列过滤
        $portIdx = $find(['端口']);
        if ($portIdx === null) {
            return [];
        }
        $remarkIdx = $find(['国家', '城市', '数据中心', '地区']);
        $tlsIdx = $find(['TLS']);

        $entries = [];
        foreach ($dataLines as $line) {
            $cols = array_map('trim', str_getcsv($line));
            if (count($cols) <= max($ipIdx, $portIdx)) {
                continue;
            }
            if ($tlsIdx !== null && isset($cols[$tlsIdx]) && strtolower($cols[$tlsIdx]) !== 'true') {
                continue;
            }

            $ip = $this->normalizeIp($cols[$ipIdx]);
            if ($ip === null) {
                continue;
            }

            $region = $remarkIdx !== null && isset($cols[$remarkIdx]) ? $cols[$remarkIdx] : null;
            $port = is_numeric($cols[$portIdx] ?? null) ? (int) $cols[$portIdx] : null;

            $entries[] = [
                'ip' => $ip,
                'remark' => $this->buildRemark($region ?: null, null, $port),
                'latency_ms' => null,
                'download_speed' => null,
            ];
        }

        return $entries;
    }

    /**
     * 测速版 CSV：延迟 / 速度写入结构化指标字段
     */
    private function parseSpeedTestCsv(array $dataLines, int $ipIdx, int $latencyIdx, int $speedIdx): array
    {
        $entries = [];

        foreach ($dataLines as $line) {
            $cols = array_map('trim', str_getcsv($line));
            if (count($cols) <= max($ipIdx, $latencyIdx, $speedIdx)) {
                continue;
            }

            $ip = $this->normalizeIp($cols[$ipIdx]);
            if ($ip === null) {
                continue;
            }

            $entries[] = [
                'ip' => $ip,
                'remark' => null,
                'latency_ms' => is_numeric($cols[$latencyIdx]) ? (float) $cols[$latencyIdx] : null,
                'download_speed' => is_numeric($cols[$speedIdx]) ? (float) $cols[$speedIdx] : null,
            ];
        }

        return $entries;
    }

    /**
     * IP 规整：支持 CIDR 网段采样；IPv6 补方括号存储，非 IP 返回 null
     */
    private function normalizeIp(string $value): ?string
    {
        $value = trim($value, " \t\"'[]");
        if ($value === '') {
            return null;
        }

        // CIDR 网段：每段采样一个代表 IP 参与测速（v4 取段内首个可用地址，v6 取网络地址——CF anycast 段内等价）
        if (str_contains($value, '/')) {
            [$ip, $prefix] = explode('/', $value, 2);
            if (!ctype_digit($prefix)) {
                return null;
            }
            $prefix = (int) $prefix;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $prefix <= 32) {
                $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));
                $net = ip2long($ip) & $mask;

                return long2ip($prefix === 32 ? $net : $net + 1);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $prefix <= 128) {
                return '[' . $ip . ']';
            }

            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '[' . $value . ']';
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }

        return null;
    }

    /**
     * 备注组装：行备注优先，其次源名；非 443 端口附加说明
     */
    private function buildRemark(?string $remark, ?string $fallbackRemark, ?int $port): ?string
    {
        $final = $remark !== null && $remark !== '' ? $remark : $fallbackRemark;
        if ($final === null || $final === '') {
            $final = null;
        }

        if ($port !== null && $port !== 443) {
            $final = ($final !== null ? $final . ' · ' : '') . '端口' . $port;
        }

        return $final;
    }

    /**
     * 响应体解码：UTF-8 校验失败按 GBK 转换（参考 work.js 的多编码策略）
     */
    private function decodeBody(string $body): string
    {
        if (mb_check_encoding($body, 'UTF-8')) {
            return $body;
        }

        return mb_convert_encoding($body, 'UTF-8', 'GBK');
    }

    /**
     * 按 IP 唯一键入库：备注/指标以源数据为准，null 字段保留原值
     */
    private function upsertIp(array $entry): void
    {
        PreferredIp::updateOrCreate(
            ['ip' => $entry['ip']],
            array_filter([
                'remarks' => $entry['remark'],
                'latency_ms' => $entry['latency_ms'],
                'download_speed' => $entry['download_speed'],
            ], fn ($v) => $v !== null)
        );
    }
}
