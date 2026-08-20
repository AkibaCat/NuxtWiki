<?php
declare(strict_types=1);

/**
 * 轻量迁移：为已有数据库补充新表 / 新列（幂等，每次请求执行一次）。
 * 全新安装直接使用 schema.*.sql，无需迁移。
 */
final class Migrate
{
    public static function ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $db = Database::pdo();
            $driver = Database::driver();

            // 1) users 补充个人资料字段
            $added = self::addColumns($db, $driver, 'users', [
                'nickname' => 'TEXT NOT NULL DEFAULT \'\'',
                'bio'      => 'TEXT NOT NULL DEFAULT \'\'',
                'avatar'   => 'TEXT NOT NULL DEFAULT \'\'',
                'socials'  => 'TEXT NOT NULL DEFAULT \'\'',
                'status'   => 'TEXT NOT NULL DEFAULT \'active\'',
                'reason'   => 'TEXT NOT NULL DEFAULT \'\'',
                'level'    => 'TINYINT NOT NULL DEFAULT 3',
            ]);
            // 首次引入 level 时，将已有管理员归一为等级 1
            if (in_array('level', $added, true)) {
                $db->exec('UPDATE ' . Database::qi('users') . ' SET ' . Database::qi('level') . ' = 1 WHERE ' . Database::qi('is_admin') . ' = 1');
            }

            // 1.1) pages 补充按等级细分的页面操作权限（阅读/编辑/历史/对比/回链/权限管理/贡献者）
            self::addColumns($db, $driver, 'pages', [
                'acl_read'         => 'TEXT NOT NULL DEFAULT \'0\'',
                'acl_edit'         => 'TEXT NOT NULL DEFAULT \'3\'',
                'acl_history'      => 'TEXT NOT NULL DEFAULT \'3\'',
                'acl_diff'         => 'TEXT NOT NULL DEFAULT \'2\'',
                'acl_backlinks'    => 'TEXT NOT NULL DEFAULT \'3\'',
                'acl_acl'          => 'TEXT NOT NULL DEFAULT \'1\'',
                'acl_contributors' => 'TEXT NOT NULL DEFAULT \'0\'',
            ]);

            // 1.2) pages / revisions 补充页面样式表（SCSS 源文本）
            self::addColumns($db, $driver, 'pages', [
                'style' => 'LONGTEXT',
            ]);
            self::addColumns($db, $driver, 'revisions', [
                'style' => 'LONGTEXT',
            ]);

            // 2) 注册码表
            if ($driver === 'mysql') {
                $db->exec(
                    'CREATE TABLE IF NOT EXISTS regcodes ('
                    . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                    . 'code VARCHAR(32) NOT NULL, '
                    . 'user_id INT UNSIGNED NULL, '
                    . 'created_at DATETIME NOT NULL, '
                    . 'used_at DATETIME NULL, '
                    . 'PRIMARY KEY (id), '
                    . 'UNIQUE KEY uq_regcodes_code (code), '
                    . 'KEY idx_regcodes_user (user_id)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            } else {
                $db->exec(
                    'CREATE TABLE IF NOT EXISTS regcodes ('
                    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                    . 'code TEXT NOT NULL UNIQUE, '
                    . 'user_id INTEGER NULL, '
                    . 'created_at TEXT NOT NULL, '
                    . 'used_at TEXT NULL'
                    . ')'
                );
            }
        } catch (Throwable $e) {
            // 迁移失败不阻断主流程（记录日志，交由安装/重建解决）
            error_log('[NuxtWiki] migrate: ' . $e->getMessage());
        }
    }

    /** 补充缺失列（SQLite 用 PRAGMA table_info，MySQL 用 SHOW COLUMNS），返回实际新增的列名 */
    private static function addColumns(PDO $db, string $driver, string $table, array $columns): array
    {
        if ($driver === 'mysql') {
            $st = $db->query('SHOW COLUMNS FROM ' . Database::qi($table));
            $existing = [];
            foreach ($st->fetchAll() as $r) {
                $existing[] = (string)$r['Field'];
            }
        } else {
            $st = $db->query('PRAGMA table_info(' . Database::qi($table) . ')');
            $existing = [];
            foreach ($st->fetchAll() as $r) {
                $existing[] = (string)$r['name'];
            }
        }
        $added = [];
        foreach ($columns as $name => $def) {
            if (!in_array($name, $existing, true)) {
                $db->exec('ALTER TABLE ' . Database::qi($table) . ' ADD COLUMN ' . Database::qi($name) . ' ' . $def);
                $added[] = $name;
            }
        }
        return $added;
    }
}
