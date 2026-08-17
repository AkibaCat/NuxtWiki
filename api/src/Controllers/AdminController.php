<?php
declare(strict_types=1);

/** 管理后台：统计 / 站点设置 / 备份 */
final class AdminController
{
    /** GET admin.stats */
    public static function stats(): never
    {
        Auth::requireAdmin();
        $db = Database::pdo();
        $count = fn(string $table): int => (int)$db->query('SELECT COUNT(*) FROM ' . Database::qi($table))->fetchColumn();
        $sumHits = (int)$db->query('SELECT COALESCE(SUM(' . Database::qi('hits') . '),0) FROM ' . Database::qi('pages'))->fetchColumn();
        Response::data([
            'pages'       => $count('pages'),
            'revisions'   => $count('revisions'),
            'users'       => $count('users'),
            'watchers'    => $count('watchers'),
            'regcodes'    => $count('regcodes'),
            'total_hits'  => $sumHits,
            'driver'      => Database::driver(),
            'version'     => '1.0.0',
        ]);
    }

    /** GET admin.settings */
    public static function settingsGet(): never
    {
        Auth::requireAdmin();
        $keys = [
            'site_name', 'site_description', 'site_footer', 'home_tag', 'language',
            'allow_registration', 'default_read_level', 'default_edit_level', 'default_history_level',
            'default_diff_level', 'default_backlinks_level', 'default_perms_level', 'default_contributors_level',
        ];
        $out = [];
        foreach ($keys as $k) {
            $cfg = app_config()['site'][$k] ?? '';
            $out[$k] = Settings::get($k, is_bool($cfg) ? ($cfg ? '1' : '0') : (string)$cfg);
        }
        Response::data($out);
    }

    /** POST admin.settings */
    public static function settingsSave(): never
    {
        Auth::requireAdmin();
        Auth::verifyCsrf();
        $b = Response::body();
        $keys = [
            'site_name', 'site_description', 'site_footer', 'home_tag', 'language',
            'allow_registration', 'default_read_level', 'default_edit_level', 'default_history_level',
            'default_diff_level', 'default_backlinks_level', 'default_perms_level', 'default_contributors_level',
        ];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $b)) {
                continue;
            }
            $v = is_bool($b[$k]) ? ($b[$k] ? '1' : '0') : (string)$b[$k];
            if ($k === 'home_tag' && trim($v) !== '') {
                $v = Text::normalizeTag($v);
            }
            if (str_starts_with($k, 'default_') && str_ends_with($k, '_level') && !in_array($v, ['0', '1', '2', '3'], true)) {
                Response::error('默认权限等级只能为 0~3。', 422, 'INVALID_INPUT');
            }
            Settings::set($k, $v);
        }
        Response::data(['ok' => true]);
    }

    /** GET admin.backup —— 导出 JSON 备份 */
    public static function backup(): never
    {
        Auth::requireAdmin();
        $db = Database::pdo();
        $dump = [
            'exported_at' => Database::now(),
            'app'         => 'NuxtWiki',
            'version'     => '1.0.0',
        ];
        foreach (['users', 'pages', 'revisions', 'watchers', 'regcodes', 'settings'] as $table) {
            $dump[$table] = $db->query('SELECT * FROM ' . Database::qi($table))->fetchAll();
        }
        $json = json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        Response::download($json, 'nuxtwiki-backup-' . date('Ymd-His') . '.json', 'application/json');
    }

    /** POST admin.restore —— 导入 JSON 备份（兼容旧版导出数据，全量覆盖） */
    public static function restore(): never
    {
        Auth::requireAdmin();
        Auth::verifyCsrf();
        $json = '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $json = (string)file_get_contents($_FILES['file']['tmp_name']);
        } else {
            $json = (string)(Response::body()['data'] ?? '');
        }
        if (trim($json) === '') {
            Response::error('未收到备份内容。', 422, 'INVALID_INPUT');
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            Response::error('备份文件不是有效的 JSON。', 422, 'INVALID_BACKUP');
        }

        $db = Database::pdo();
        $driver = Database::driver();

        // 已知表清单（旧版导出仅含 users/pages/revisions/watchers）
        $tables = ['users', 'pages', 'revisions', 'watchers', 'regcodes', 'settings'];
        $present = [];
        foreach ($tables as $t) {
            if (isset($data[$t]) && is_array($data[$t]) && count($data[$t]) > 0) {
                $present[] = $t;
            }
        }
        if (!$present) {
            Response::error('备份中不包含任何可导入的数据。', 422, 'INVALID_BACKUP');
        }

        // 取某表当前列（按实际表结构过滤备份行，兼容字段差异）
        $columnsOf = function (string $table) use ($db, $driver): array {
            if ($driver === 'mysql') {
                $st = $db->query('SHOW COLUMNS FROM ' . Database::qi($table));
                $cols = [];
                foreach ($st->fetchAll() as $r) {
                    $cols[] = (string)$r['Field'];
                }
                return $cols;
            }
            $st = $db->query('PRAGMA table_info(' . Database::qi($table) . ')');
            $cols = [];
            foreach ($st->fetchAll() as $r) {
                $cols[] = (string)$r['name'];
            }
            return $cols;
        };

        // 外键顺序：先删引用方（仅删除备份中存在的表，避免误清站点设置等）
        $clearOrder = ['watchers', 'revisions', 'regcodes', 'pages', 'users', 'settings'];
        $deleteOrder = array_values(array_filter($clearOrder, fn($t) => in_array($t, $present, true)));

        $db->beginTransaction();
        try {
            foreach ($deleteOrder as $t) {
                $db->exec('DELETE FROM ' . Database::qi($t));
            }
            $counts = [];
            foreach ($present as $t) {
                $cols = $columnsOf($t);
                $statements = [];
                $n = 0;
                foreach ($data[$t] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $ins = [];
                    $vals = [];
                    foreach ($cols as $col) {
                        if (array_key_exists($col, $row)) {
                            $ins[] = Database::qi($col);
                            $v = $row[$col];
                            $vals[] = is_bool($v) ? ($v ? 1 : 0) : $v;
                        }
                    }
                    if (!$ins) {
                        continue;
                    }
                    $sig = implode('|', $ins);
                    if (!isset($statements[$sig])) {
                        $sql = 'INSERT INTO ' . Database::qi($t) . ' (' . implode(', ', $ins) . ') VALUES (' . implode(', ', array_fill(0, count($ins), '?')) . ')';
                        $statements[$sig] = $db->prepare($sql);
                    }
                    $statements[$sig]->execute(array_values($vals));
                    $n++;
                }
                $counts[$t] = $n;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Response::error('导入失败：' . $e->getMessage(), 422, 'IMPORT_FAILED');
        }

        Response::data(['ok' => true, 'imported' => $counts]);
    }

    /** GET admin.pages —— 页面清单（含大小/编辑者） */
    public static function pages(): never
    {
        Auth::requireAdmin();
        $rows = Database::pdo()->query(
            'SELECT p.' . Database::qi('id') . ', p.' . Database::qi('tag') . ', p.' . Database::qi('title') . ', '
            . 'p.' . Database::qi('hits') . ', p.' . Database::qi('revision') . ', p.' . Database::qi('updated_at') . ', '
            . 'p.' . Database::qi('acl_read') . ', p.' . Database::qi('acl_edit') . ', p.' . Database::qi('acl_history') . ', '
            . 'p.' . Database::qi('acl_diff') . ', p.' . Database::qi('acl_backlinks') . ', p.' . Database::qi('acl_acl') . ', '
            . 'p.' . Database::qi('acl_contributors') . ', u.' . Database::qi('username')
            . ' FROM ' . Database::qi('pages') . ' p'
            . ' LEFT JOIN ' . Database::qi('users') . ' u ON u.' . Database::qi('id') . ' = p.' . Database::qi('user_id')
            . ' ORDER BY p.' . Database::qi('updated_at') . ' DESC'
        )->fetchAll();
        Response::data(array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'tag'        => (string)$r['tag'],
            'title'      => (string)$r['title'],
            'hits'       => (int)$r['hits'],
            'revision'   => (int)$r['revision'],
            'last_editor'=> $r['username'] !== null ? (string)$r['username'] : null,
            'updated_at' => (string)$r['updated_at'],
            'acl'        => '读' . (string)$r['acl_read'] . '/编' . (string)$r['acl_edit'] . '/史' . (string)$r['acl_history']
                . '/比' . (string)$r['acl_diff'] . '/回' . (string)$r['acl_backlinks'] . '/权' . (string)$r['acl_acl']
                . '/贡' . (string)$r['acl_contributors'],
        ], $rows));
    }
}
