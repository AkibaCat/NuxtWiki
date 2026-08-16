<?php
declare(strict_types=1);

/**
 * 极简路由：r=<controller>.<action> + HTTP 方法。
 * 例如 GET  ?r=page.get   POST ?r=page.save
 */
final class Router
{
    /** @var array<string, array{0:class-string, 1:string}> key = "METHOD action" */
    private static array $routes = [];

    /** 注册路由：$method 为 'GET'|'POST'|'PUT'|'DELETE'，$action 形如 page.get */
    public static function add(string $method, string $action, string $class, string $handler): void
    {
        self::$routes[strtoupper($method) . ' ' . $action] = [$class, $handler];
    }

    /** 根据 $_GET['r'] 与当前方法分发 */
    public static function dispatch(): never
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $action = strtolower(trim((string)($_GET['r'] ?? '')));
        if ($action === '') {
            $action = 'page.index'; // 默认首页
        }

        $key = $method . ' ' . $action;
        if (!isset(self::$routes[$key])) {
            Response::error('接口不存在: ' . $method . ' ' . $action, 404, 'NOT_FOUND');
        }

        [$class, $handler] = self::$routes[$key];
        $class::$handler();

        // 理论上不应到达
        Response::error('空响应', 500, 'EMPTY');
    }
}
