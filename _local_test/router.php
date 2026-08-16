<?php
// 本地测试路由：合并静态 SPA + PHP API + SPA 回退
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;

// 静态文件直接返回
if ($uri !== '/' && is_file($file)) {
    return false;
}

// API 请求转给 api/index.php
if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

// SPA 回退到 index.html
require __DIR__ . '/index.html';
return true;
