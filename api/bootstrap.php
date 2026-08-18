<?php
/**
 * NuxtWiki · 启动引导
 * 加载配置与核心类，仅在非 CLI 下对外部调用开放。
 */

declare(strict_types=1);

// ---------- 版本 ----------
/** NuxtWiki 当前版本号（发布时更新，与 Release Notes 文件夹保持一致） */
const NUVTWIKI_VERSION = '1.1.1';

// ---------- 基础环境 ----------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Shanghai');

// 若未安装（config.php 缺失），返回提示
if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => ['code' => 'NOT_INSTALLED', 'message' => 'NuxtWiki 尚未安装，请访问 /api/install.php 完成安装。'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 全局配置（加载一次并缓存） */
function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

// ---------- 核心类 ----------
require __DIR__ . '/src/Response.php';
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/Settings.php';
require __DIR__ . '/src/Auth.php';
require __DIR__ . '/src/Mailer.php';
require __DIR__ . '/src/Text.php';
require __DIR__ . '/src/Router.php';
require __DIR__ . '/src/Migrate.php';

// 已有数据库自动补充新表 / 新列（幂等）
Migrate::ensure();

// ---------- 控制器 ----------
require __DIR__ . '/src/Controllers/AuthController.php';
require __DIR__ . '/src/Controllers/UserController.php';
require __DIR__ . '/src/Controllers/PageController.php';
require __DIR__ . '/src/Controllers/RegCodeController.php';
require __DIR__ . '/src/Controllers/WatchController.php';
require __DIR__ . '/src/Controllers/FeedController.php';
require __DIR__ . '/src/Controllers/AdminController.php';
