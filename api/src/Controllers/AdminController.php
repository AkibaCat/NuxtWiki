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
            'version'     => NUVTWIKI_VERSION,
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
            'version'     => NUVTWIKI_VERSION,
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

    /** GET admin.version-check —— 版本更新检查（读取仓库 package.json 与 Release Notes） */
    public static function versionCheck(): never
    {
        Auth::requireAdmin();
        $force = ($_GET['refresh'] ?? '') === '1';
        $info = self::fetchVersionInfo($force);
        Response::data([
            'current_version' => NUVTWIKI_VERSION,
            'latest_version'  => $info['latest_version'],
            'has_update'      => $info['latest_version'] !== '' && version_compare($info['latest_version'], NUVTWIKI_VERSION, '>'),
            'release_notes'   => $info['release_notes'],
            'release_url'     => $info['release_url'],
            'checked_at'      => $info['checked_at'],
        ]);
    }

    /**
     * 拉取仓库最新版本信息（package.json → 版本号，Release Notes 文件夹 → 更新说明）。
     * 结果写入 data 目录缓存（默认 6 小时），避免频繁请求 GitHub。
     *
     * @return array{latest_version:string, release_notes:string, release_url:string, checked_at:string}
     */
    private static function fetchVersionInfo(bool $force = false): array
    {
        $repo   = 'AkibaCat/NuxtWiki';
        $branch = 'main';
        $rawUrl = "https://raw.githubusercontent.com/{$repo}/{$branch}";
        $dataDir = rtrim((string)(app_config()['uploads']['data_dir'] ?? (__DIR__ . '/../../data')), '/\\');
        $cacheFile = $dataDir . DIRECTORY_SEPARATOR . 'version_cache.json';
        $ttl = 6 * 3600;

        $cached = [];
        if (is_file($cacheFile)) {
            $decoded = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($decoded)) {
                $cached = $decoded;
            }
        }
        // 缓存命中（未过期）且非强制刷新时直接返回
        if (!$force && isset($cached['fetched_at']) && (time() - (int)$cached['fetched_at']) < $ttl) {
            return $cached;
        }

        $info = [
            'latest_version' => (string)($cached['latest_version'] ?? ''),
            'release_notes'  => (string)($cached['release_notes'] ?? ''),
            'release_url'    => "https://github.com/{$repo}/releases",
            'checked_at'     => Database::now(),
        ];

        // 1. 读取仓库 package.json 判断最新版本
        $pkgRaw = self::httpGet($rawUrl . '/package.json');
        $pkg = $pkgRaw !== '' ? json_decode($pkgRaw, true) : null;
        if (!is_array($pkg)) {
            // 拉取失败：有缓存则沿用（可能已过期，仍优于空结果）
            return $cached ?: $info;
        }
        $latest = '';
        $vid    = '';
        if (is_array($pkg['versions'] ?? null)) {
            $latest = (string)($pkg['versions']['version'] ?? '');
            $vid    = (string)($pkg['versions']['id'] ?? '');
        } elseif (isset($pkg['version'])) {
            $latest = (string)$pkg['version'];
        }
        $info['latest_version'] = $latest;

        // 2. 存在新版本时，读取 Release Notes 文件夹中对应版本说明（文件名形如 [3]1-1-1.md）
        if ($latest !== '' && $vid !== '' && version_compare($latest, NUVTWIKI_VERSION, '>')) {
            $dashed = str_replace('.', '-', $latest);
            $notes  = self::httpGet($rawUrl . '/' . rawurlencode('Release Notes') . '/' . rawurlencode("[{$vid}]{$dashed}.md"));
            if ($notes !== '') {
                $info['release_notes'] = $notes;
            }
        }
        $info['checked_at'] = Database::now();

        // 写入缓存（data 目录不可写时忽略）
        $info['fetched_at'] = time();
        if (is_dir($dataDir) && is_writable($dataDir)) {
            @file_put_contents($cacheFile, json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        unset($info['fetched_at']);
        return $info;
    }

    /** 简单 HTTP GET（file_get_contents 优先，失败时回退 cURL），失败返回空串 */
    private static function httpGet(string $url): string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'NuxtWiki-Updater/1.0']]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw !== false) {
            return (string)$raw;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'NuxtWiki-Updater/1.0',
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            return is_string($raw) ? $raw : '';
        }
        return '';
    }
}
