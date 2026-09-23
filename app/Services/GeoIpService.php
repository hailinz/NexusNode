<?php

namespace App\Services;

use MaxMind\Db\Reader;

/**
 * 本地 GeoIP 查询（基于 MaxMind mmdb 文件，软依赖）。
 *
 * 行为契约：
 * - 文件未配置 / 不存在 / 无效 → lookup() 返回 null，不抛异常（不阻塞订阅服务）
 * - 私有 IP（RFC 1918 / RFC 4193 / loopback / link-local）→ 返回 null
 * - 库无该 IP 记录 → 返回 null
 * - 命中 → 返回可读字符串（"中国 上海 / 上海电信"），无字段则跳过
 *
 * 性能：每个进程复用单个 Reader 实例（mmdb 文件 mmap 后常驻内存）。
 */
class GeoIpService
{
    private ?Reader $reader = null;

    private ?bool $available = null;

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $path = (string) config('geoip.database_path', '');

        return $this->available = ($path !== '' && is_file($path) && is_readable($path));
    }

    /**
     * IP → 位置字符串。失败一律返回 null（订阅日志可继续写入）。
     */
    public function lookup(string $ip): ?string
    {
        if (! $this->isAvailable() || $this->isPrivateIp($ip)) {
            return null;
        }

        try {
            $record = $this->getReader()->get($ip);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($record)) {
            return null;
        }

        $place = $this->extractPlace($record);
        $asn = $this->extractAsn($record);

        if ($place === null && $asn === null) {
            return null;
        }

        return trim(($place ?? '').(($place !== null && $asn !== null) ? ' / ' : '').($asn ?? ''), ' /');
    }

    /**
     * mmdb 文件路径，供 artisan 命令展示 / 写入文档。
     */
    public function databasePath(): string
    {
        return (string) config('geoip.database_path', '');
    }

    public function close(): void
    {
        if ($this->reader !== null) {
            try {
                $this->reader->close();
            } catch (\Throwable) {
                // ignore
            }
            $this->reader = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function getReader(): Reader
    {
        if ($this->reader === null) {
            $this->reader = new Reader($this->databasePath());
        }

        return $this->reader;
    }

    /**
     * 私有/保留 IP 不查 GeoIP（内网、loopback、IPv6 link-local 等）
     */
    private function isPrivateIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true; // 非法 IP 一律视为「不可查」
        }

        // filter_var 在 ipv4/ipv6 上分别用不同 flag
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        return ! filter_var($ip, FILTER_VALIDATE_IP, $flags);
    }

    /**
     * 从 mmdb 记录中抽取可读位置（国家 + 一级行政区 + 城市），用本地化名称优先。
     * 支持 MaxMind GeoLite2-City 与 db-ip.com ip-to-city 两种 schema（字段路径略有差异）。
     */
    private function extractPlace(array $record): ?string
    {
        $country = $this->localizedName($record['country']['names'] ?? $record['country_name']['names'] ?? null);
        $subdivision = $this->localizedName(
            $record['subdivisions'][0]['names'] ?? $record['region']['names'] ?? null
        );
        $city = $this->localizedName($record['city']['names'] ?? null);

        $parts = array_filter([$country, $subdivision, $city], fn ($s) => $s !== null && $s !== '');
        if (empty($parts)) {
            return null;
        }

        return implode(' ', $parts);
    }

    /**
     * ASN 组织名（如"上海电信" / "Cloudflare"）。
     * MaxMind: `autonomous_system_organization`；db-ip: `as.name` 或 `as.organization`。
     */
    private function extractAsn(array $record): ?string
    {
        $candidates = [
            $record['autonomous_system_organization'] ?? null,
            $record['as']['organization'] ?? null,
            $record['as']['name'] ?? null,
            $record['autonomous_organization'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return trim($c);
            }
        }

        return null;
    }

    /**
     * 从 names 多语言表中挑第一个非空值（zh-CN > en > 其它）。
     */
    private function localizedName(?array $names): ?string
    {
        if (! is_array($names)) {
            return null;
        }

        foreach (['zh-CN', 'zh', 'en'] as $lang) {
            if (! empty($names[$lang])) {
                return $names[$lang];
            }
        }

        foreach ($names as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }
}
