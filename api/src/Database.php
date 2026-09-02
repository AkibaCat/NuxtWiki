<?php
declare(strict_types=1);

use PDO;

/**
 * 数据库连接（PDO）
 * 支持 MySQL（生产）与 SQLite（本地开发/测试）双后端。
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static ?string $driver = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $cfg = app_config()['db'];
            $driver = ($cfg['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            if ($driver === 'mysql') {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $cfg['host'] ?? '127.0.0.1',
                    (int)($cfg['port'] ?? 3306),
                    $cfg['name'] ?? 'nuxtwiki',
                    $cfg['charset'] ?? 'utf8mb4'
                );
                // 持久连接：复用进程内存活的 MySQL 连接，避免每个请求对（外部）数据库冷建连接，
                // 可显著降低外部数据库场景下的页面延迟。见 connectPersistent()。
                $options[PDO::ATTR_PERSISTENT] = true;
                self::$pdo = self::connectPersistent($dsn, $cfg['user'] ?? 'root', $cfg['password'] ?? '', $options);
            } else {
                $path = $cfg['sqlite_path'] ?? (__DIR__ . '/../data/nuxtwiki.sqlite');
                $dir  = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                }
                self::$pdo = new PDO('sqlite:' . $path, null, null, $options);
                // SQLite：手动启用外键约束与 WAL 日志，保证数据完整性与并发读写
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                self::$pdo->exec('PRAGMA journal_mode = WAL');
            }
            self::$driver = $driver;
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        self::pdo();
        return self::$driver ?? 'sqlite';
    }

    /** 标识符加引号（MySQL 反引号 / SQLite 双引号） */
    public static function qi(string $ident): string
    {
        return self::driver() === 'mysql' ? "`$ident`" : "\"$ident\"";
    }

    public static function lastInsertId(): int
    {
        return (int)self::pdo()->lastInsertId();
    }

    /**
     * 建立 MySQL 连接（优先常驻复用）。进程内复用的持久连接可能因长时空闲被服务端关闭
     * （MySQL server has gone away），因此建连后做一次轻量探活；失效则回退为一次性连接重试，
     * 保证接口不因连接过期而报 500。
     */
    private static function connectPersistent(string $dsn, string $user, string $pass, array $options): PDO
    {
        for ($try = 0; $try < 2; $try++) {
            // 第一次用常驻连接；若池内连接已失效，第二次改为一次性连接规避坏句柄
            $options[PDO::ATTR_PERSISTENT] = $try === 0;
            $pdo = new PDO($dsn, $user, $pass, $options);
            $pdo->query('SELECT 1');
            return $pdo;
        }
        throw new RuntimeException('无法连接数据库');
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** 是否已安装（settings 表存在） */
    public static function installed(): bool
    {
        try {
            self::pdo()->query('SELECT COUNT(*) FROM ' . self::qi('settings'));
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
