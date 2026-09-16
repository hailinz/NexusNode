<?php

/**
 * PHP 内置服务器路由脚本（Laravel 12 不再自带 server.php）
 * 用法：php -S 127.0.0.1:8000 public/router.php
 * 静态文件（/vendor/*、CSS、图片等）由内置服务器直接返回，其余请求进入 Laravel。
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$uri = str_replace(['//', '../'], '/', $uri);

if ($uri !== '/' && is_file(__DIR__.$uri)) {
    return false;
}

require_once __DIR__.'/index.php';
