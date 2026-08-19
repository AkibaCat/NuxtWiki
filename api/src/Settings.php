<?php
declare(strict_types=1);

/** 站点设置（settings 表，运行时可在后台修改） */
final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                $rows = Database::pdo()->query('SELECT ' . Database::qi('skey') . ', ' . Database::qi('svalue') . ' FROM ' . Database::qi('settings'));
                foreach ($rows as $row) {
                    self::$cache[(string)$row['skey']] = (string)$row['svalue'];
                }
            } catch (Throwable) {
                // 未安装等场景下忽略
            }
        }
        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        $v = self::all()[$key] ?? null;
        return $v === null || $v === '' ? $default : $v;
    }

    public static function set(string $key, string $value): void
    {
        $db = Database::pdo();
        $st = $db->prepare(
            'INSERT INTO ' . Database::qi('settings') . ' (' . Database::qi('skey') . ', ' . Database::qi('svalue') . ') VALUES (?, ?) ' .
            'ON DUPLICATE KEY UPDATE ' . Database::qi('svalue') . ' = VALUES(' . Database::qi('svalue') . ')'
        );
        // SQLite 不支持 ON DUPLICATE KEY，改用 dialect 分支
        if (Database::driver() === 'sqlite') {
            $st = $db->prepare(
                'INSERT INTO ' . Database::qi('settings') . ' (' . Database::qi('skey') . ', ' . Database::qi('svalue') . ') VALUES (?, ?) ' .
                'ON CONFLICT(' . Database::qi('skey') . ') DO UPDATE SET ' . Database::qi('svalue') . ' = excluded.' . Database::qi('svalue')
            );
        }
        $st->execute([$key, $value]);
        self::$cache = null; // 失效缓存
    }

    public static function siteName(): string
    {
        return self::get('site_name', app_config()['site']['name'] ?? 'NuxtWiki');
    }

    public static function siteDescription(): string
    {
        return self::get('site_description', app_config()['site']['description'] ?? '');
    }

    public static function siteFooter(): string
    {
        return self::get('site_footer', '');
    }

    public static function homeTag(): string
    {
        return self::get('home_tag', app_config()['site']['home_tag'] ?? 'HomePage');
    }

    public static function siteLanguage(): string
    {
        $lang = self::get('language', app_config()['site']['language'] ?? 'zh-CN');
        return in_array($lang, ['zh-CN', 'zh-TW', 'en'], true) ? $lang : 'zh-CN';
    }

    public static function baseUrl(): string
    {
        $base = self::get('base_url', app_config()['site']['base_url'] ?? '');
        if ($base !== '') {
            return rtrim($base, '/');
        }
        // 自动推断
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    public static function allowRegistration(): bool
    {
        return self::get('allow_registration', app_config()['site']['allow_registration'] ? '1' : '0') === '1';
    }

    /** 页面操作的默认所需等级（0 访客 / 1 管理员 / 2 高级 / 3 普通） */
    public static function defaultLevel(string $kind): string
    {
        $defaults = [
            'read'         => '0',
            'edit'         => '3',
            'history'      => '3',
            'diff'         => '2',
            'backlinks'    => '3',
            'perms'        => '1',
            'contributors' => '0',
        ];
        $default = $defaults[$kind] ?? '3';
        return self::get('default_' . $kind . '_level', $default);
    }
}
