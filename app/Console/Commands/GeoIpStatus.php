<?php

namespace App\Console\Commands;

use App\Services\GeoIpService;
use Illuminate\Console\Command;

class GeoIpStatus extends Command
{
    /**
     * 显示 GeoIP 数据库状态（路径、文件大小、版本、可用性）。
     *
     * 若 GEOIP_DATABASE_PATH 配置无效或文件缺失，提示获取方式（MaxMind / db-ip）。
     */
    protected $signature = 'geoip:status';

    protected $description = '显示 GeoIP 数据库状态与获取指引';

    public function handle(GeoIpService $geoIp): int
    {
        $path = $geoIp->databasePath();

        $this->line('GeoIP 数据库状态');
        $this->line('─────────────────');
        $this->line('路径：   '.($path ?: '(未配置)'));

        if ($path === '') {
            $this->warn('GEOIP_DATABASE_PATH 未配置，地理位置查询已禁用。');
            $this->newLine();
            $this->info('获取方式：');
            $this->line('  - MaxMind GeoLite2（需注册）：https://www.maxmind.com/en/geolite2/signup');
            $this->line('  - db-ip.com（免费，免注册）：https://db-ip.com/db/download/ip-to-city');
            $this->newLine();
            $this->line('下载后，设置 .env：');
            $this->line('  GEOIP_DATABASE_PATH=/path/to/ip-to-city.mmdb');

            return self::SUCCESS;
        }

        if (! $geoIp->isAvailable()) {
            $this->error("文件不存在或不可读：{$path}");
            $this->newLine();
            $this->info('请确认路径正确且文件存在。');

            return self::FAILURE;
        }

        $size = filesize($path);
        $this->line('大小：   '.$this->formatSize($size));
        $this->line('状态：   '.'<fg=green>可用</>');
        $this->newLine();
        $this->info('地理位置查询已启用 — 每次订阅拉取时会同步记录 location 字段。');

        return self::SUCCESS;
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
