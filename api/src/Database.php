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
                self::$pdo = new PDO($dsn, $cfg['user'] ?? 'root', $cfg['password'] ?? '', $options);
            } else {
                $path = $cfg['sqlite_path'] ?? (__DIR__ . '/../data/nuxtwiki.sqlite');
                $dir  = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                }
                self::$pdo = new PDO('sqlite:' . $path, null, null, $options);
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
