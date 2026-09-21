<?php

namespace App\Models;

use App\Services\ProxyUriBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Node extends Model
{
    protected $fillable = [
        'name', 'protocol', 'uuid', 'address', 'port', 'security', 'sni', 'host',
        'path', 'network', 'flow', 'fingerprint', 'encryption', 'alpn', 'extras',
        'enabled', 'is_generated', 'is_cf', 'parent_node_id', 'sort_order',
    ];

    protected $casts = [
        'extras' => 'array',
        'enabled' => 'boolean',
        'is_generated' => 'boolean',
        'is_cf' => 'boolean',
        'port' => 'integer',
        'parent_node_id' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        // 保存时自动重算 CF 优选标记（手动编辑、导入、优选生成均覆盖）
        static::saving(function (Node $node) {
            $node->is_cf = self::computeIsCf($node->address, $node->sni, $node->port);
        });
    }

    /**
     * CF 优选节点判定，仅两个条件：
     * 1. 端口为 443
     * 2. 地址与 SNI 不同（规范化后比较：地址剥离端口尾巴与 IPv6 方括号、域名不区分大小写）
     */
    public static function computeIsCf(?string $address, ?string $sni, ?int $port = null): bool
    {
        if ((int) $port !== 443) {
            return false;
        }

        $sni = rtrim(strtolower(trim((string) $sni)), '.');
        if ($sni === '') {
            return false;
        }

        $addr = strtolower(trim((string) $address));
        if ($addr === '') {
            return false;
        }

        // [IPv6] 或 [IPv6]:端口
        if (preg_match('/^\[(.+)\](?::\d+)?$/', $addr, $m)) {
            $addr = $m[1];
        } elseif (substr_count($addr, ':') === 1) {
            // IPv4:端口 形态，剥端口（裸 IPv6 含多个冒号，不落入此分支）
            $addr = explode(':', $addr)[0];
        }

        return rtrim($addr, '.') !== $sni;
    }

    /**
     * 生成来源节点（优选生成的节点才有）
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'parent_node_id');
    }

    /**
     * 由该节点生成的优选节点
     */
    public function children(): HasMany
    {
        return $this->hasMany(Node::class, 'parent_node_id');
    }

    /**
     * 包含该节点的订阅白名单（用于反向校验、统计）
     */
    public function subscriptions(): BelongsToMany
    {
        return $this->belongsToMany(Subscription::class, 'subscription_node')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /**
     * 由结构化字段重建完整代理链接（供列表复制与订阅输出）
     */
    public function getUriAttribute(): string
    {
        return ProxyUriBuilder::build($this);
    }

    /**
     * 仅启用的节点（进入订阅）
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * CF 优选 IP 节点（物化字段，保存时自动计算）
     */
    public function scopeCfPreferred(Builder $query): Builder
    {
        return $query->where('is_cf', true);
    }

    /**
     * 可作为优选模板的节点：443 端口、直连自身域名（规范化地址与 SNI 一致）。
     * 语义：模板提供「真实域名 + 443」的连接参数，生成时仅把地址替换为优选 IP。
     * 在 port=443 前提下 is_cf=false 恰好等价于「地址与 SNI 一致」，直接复用物化字段。
     */
    public function scopeCfTemplate(Builder $query): Builder
    {
        return $query->where('port', 443)
            ->where('is_cf', false)
            ->whereNotNull('sni')
            ->where('sni', '!=', '');
    }

    /**
     * 与已有节点判重的业务键：协议+凭据+地址+端口+path
     */
    public static function duplicateMatchAttributes(): array
    {
        return ['protocol', 'uuid', 'address', 'port', 'path'];
    }
}
