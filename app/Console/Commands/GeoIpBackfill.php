<?php

namespace App\Console\Commands;

use App\Models\SubscriptionRequest;
use App\Services\GeoIpService;
use Illuminate\Console\Command;

class GeoIpBackfill extends Command
{
    /**
     * 回填存量 SubscriptionRequest 的 location 字段。
     *
     * 用法：
     *   php artisan geoip:backfill           # 回填全部 location=null 的记录
     *   php artisan geoip:backfill 7         # 仅回填过去 7 天的
     *   php artisan geoip:backfill 30 --chunk=500
     *
     * 适用于：装上 mmdb 库后想一次性补全历史 IP 的位置信息。
     */
    protected $signature = 'geoip:backfill {days? : 仅回填近 N 天内的记录(留空=全部)} {--chunk=1000 : 每次查询的批大小}';

    protected $description = '为历史 subscription_requests 记录补查 GeoIP location';

    public function handle(GeoIpService $geoIp): int
    {
        if (! $geoIp->isAvailable()) {
            $this->error('GeoIP 库未配置或不可读：'.$geoIp->databasePath());
            $this->line('请先安装 mmdb 文件并设置 GEOIP_DATABASE_PATH');

            return self::FAILURE;
        }

        $days = $this->argument('days');
        $chunk = (int) $this->option('chunk');

        $query = SubscriptionRequest::query()->whereNull('location');
        if ($days !== null) {
            $query->where('requested_at', '>=', now()->subDays((int) $days));
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('无需回填：所有目标记录的 location 字段已就绪');

            return self::SUCCESS;
        }

        $this->info("待回填：{$total} 条（chunk={$chunk}）");

        $updated = 0;
        $skipped = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->orderBy('id')->chunkById($chunk, function ($rows) use ($geoIp, &$updated, &$skipped, $bar) {
            foreach ($rows as $row) {
                $location = $geoIp->lookup($row->ip);
                if ($location !== null) {
                    $row->location = $location;
                    $row->saveQuietly();
                    $updated++;
                } else {
                    $skipped++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("回填完成：更新 {$updated} 条，跳过 {$skipped} 条（私有 IP / 库无记录）");

        return self::SUCCESS;
    }
}
