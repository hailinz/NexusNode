<?php

namespace App\Console\Commands;

use App\Services\GeoIpService;
use Illuminate\Console\Command;

class GeoIpTest extends Command
{
    /**
     * 用配置好的 GeoIP 库查询指定 IP 的位置信息，用于排错。
     *
     * 用法：
     *   php artisan geoip:test 8.8.8.8
     *   php artisan geoip:test 2001:4860:4860::8888
     */
    protected $signature = 'geoip:test {ip}';

    protected $description = '查询指定 IP 的 GeoIP 位置（用于诊断）';

    public function handle(GeoIpService $geoIp): int
    {
        $ip = (string) $this->argument('ip');
        $path = $geoIp->databasePath();

        $this->line("数据库路径：{$path}");
        $this->line('查询 IP：  '.$ip);
        $this->line('可用性：  '.($geoIp->isAvailable() ? '<fg=green>OK</>' : '<fg=red>不可用</>'));
        $this->line('结果：    '.($geoIp->lookup($ip) ?? '<fg=yellow>(null)</>'));

        return self::SUCCESS;
    }
}
