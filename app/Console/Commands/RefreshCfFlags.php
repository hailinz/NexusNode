<?php

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;

class RefreshCfFlags extends Command
{
    /**
     * 按当前判定规则重算全部节点的 CF 优选标记
     * （判定规则调整后执行一次即可同步存量数据）
     *
     * @var string
     */
    protected $signature = 'nodes:refresh-cf';

    protected $description = '按当前规则重算所有节点的 CF 优选标记';

    public function handle(): int
    {
        $changed = 0;
        $total = 0;

        Node::query()->orderBy('id')->chunkById(200, function ($nodes) use (&$changed, &$total) {
            foreach ($nodes as $node) {
                $total++;
                $isCf = Node::computeIsCf($node->address, $node->sni, $node->port);
                if ((bool) $node->is_cf !== $isCf) {
                    $changed++;
                }
                $node->is_cf = $isCf;
                $node->saveQuietly();
            }
        });

        $this->info("重算完成：共 {$total} 个节点，CF 标记更新 {$changed} 个");

        return self::SUCCESS;
    }
}
