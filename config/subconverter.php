<?php

/**
 * 订阅转换（SubConverter）配置
 *
 * 当管理员配置 SUB_CONVERTER_URL 时，/sub/{token} 端点会根据客户端
 * User-Agent（或 ?target= 参数）自适应输出 Clash / sing-box / Surge /
 * Quantumult X / Loon 等客户端格式。未配置时只支持 base64（mixed）。
 *
 * 兼容性: 任何符合 subconverter 后端规范的实例（如 subconverter v0.9+
 * 的 tindy2013/subconverter 或 asdlok/subconverter）。
 */

return [

    /*
    |--------------------------------------------------------------------------
    | SubConverter 基础地址
    |--------------------------------------------------------------------------
    |
    | 形如 https://subconverter.example.com/(末尾斜杠可有可无)。
    | 留空表示禁用自适应格式,只输出 base64(向后兼容)。
    |
    */

    'url' => env('SUB_CONVERTER_URL'),

    /*
    |--------------------------------------------------------------------------
    | HTTP 调用超时（秒）
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('SUB_CONVERTER_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | 客户端更新频率提示（小时）
    |--------------------------------------------------------------------------
    |
    | 通过响应头 Profile-Update-Interval 告知客户端多久拉一次。
    |
    */

    'update_interval' => (int) env('SUB_CONVERTER_UPDATE_INTERVAL', 24),

    /*
    |--------------------------------------------------------------------------
    | 远程规则集 URL（SUBCONFIG，对应 edgetunnel 的同名变量）
    |--------------------------------------------------------------------------
    |
    | 传给 SubConverter 的 config 参数，用于在 Clash / sing-box 输出中注入
    | 节点分组与流量路由规则（参考 ACL4SSR 系列模板）。
    | 留空 → 输出裸节点列表（无 rules 段），由客户端自行配置规则。
    |
    | 常用预设（仅作示例，请使用最新 URL）：
    |   - ACL4SSR_Online_Full_MultiMode.ini：全分组 + 自动测速 + 故障转移 + 负载均衡
    |   - ACL4SSR_Online_Mini.ini：精简版
    |   - ACL4SSR_Online_AdblockPlus.ini：更多去广告
    |   - ACL4SSR_Online_NoAuto.ini：无自动测速
    |
    */

    'config' => env('SUB_CONVERTER_CONFIG', 'https://raw.githubusercontent.com/ACL4SSR/ACL4SSR/master/Clash/config/ACL4SSR_Online_Full_MultiMode.ini'),
];
