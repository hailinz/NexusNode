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
];
