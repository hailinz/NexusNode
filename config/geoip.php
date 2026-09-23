<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GeoIP 数据库路径
    |--------------------------------------------------------------------------
    |
    | 指向 MaxMind GeoLite2 / db-ip 等兼容的 .mmdb 文件。留空则禁用地理位置查询。
    |
    | 获取方式（任选其一）：
    |   - MaxMind GeoLite2（需注册）：https://www.maxmind.com/en/geolite2/signup
    |     下载 GeoLite2-City.mmdb
    |   - db-ip.com（免费，免注册）：
    |     https://db-ip.com/db/download/ip-to-city
    |     下载 ip-to-city.mmdb
    |
    | 推荐：使用 artisan 命令下载与更新：
    |   php artisan geoip:update
    |
    */

    'database_path' => env('GEOIP_DATABASE_PATH', storage_path('app/geoip/GeoLite2-City.mmdb')),
];
