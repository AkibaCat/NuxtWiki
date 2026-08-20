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

    /**
     * GET admin.update-status —— 读取在线更新进度（供前端轮询，展示右上角浮窗进度）。
     * 更新过程中各步骤都会把状态写入 data 目录，失败时保留 error 信息。
     */
    public static function updateStatus(): never
    {
        Auth::requireAdmin();
        Response::data(self::readUpdateStatus());
    }

    public static function update(): never
    {
        Auth::requireAdmin();
        Auth::verifyCsrf();

        // 并发防护：已有进行中的更新任务时直接拒绝
        if ((self::readUpdateStatus()['phase'] ?? '') === 'running') {
            Response::error('已有更新任务在进行中，请稍后再试。', 409, 'UPDATE_BUSY');
        }

        $mark = function (string $title, int $percent): void {
            self::writeUpdateStatus(['phase' => 'running', 'title' => $title, 'percent' => $percent, 'at' => time()]);
        };

        try {
            $mark('prepare', 3);

            // 1. 获取最新 Release 产物包（zip 资产）下载地址
            $release = self::latestReleaseZip();
            if ($release === null) {
                throw new RuntimeException('未找到可用的 Release 产物包。');
            }
            $version = $release['version'];
            if ($version !== '' && version_compare($version, NUVTWIKI_VERSION, '<=')) {
                throw new RuntimeException('当前已是最新版本。');
            }

            // 2. 下载产物包到 data 目录
            $dataDir = self::updateDataDir();
            $zipFile = $dataDir . '/update_package.zip';
            $mark('download', 15);
            self::httpDownload($release['url'], $zipFile);

            // 3. 解压到临时目录，自动识别是否套了一层版本目录
            $extractDir = $dataDir . '/update_package';
            self::removeRecursive($extractDir);
            if (!@mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
                throw new RuntimeException('无法创建解压目录。');
            }
            $mark('extract', 45);
            self::unzip($zipFile, $extractDir);
            $srcRoot = self::detectSourceRoot($extractDir);

            // 4. 定位站点根目录（api 目录的上一级）
            $siteRoot = self::siteRoot();
            if ($siteRoot === '' || !is_dir($siteRoot)) {
                throw new RuntimeException('无法定位站点根目录。');
            }

            // 5. 覆盖部署：_nuxt/_fonts 内置哈希文件名随版本变化，先整体清空再拷入，避免旧文件残留
            $mark('clean', 60);
            foreach (['_nuxt', '_fonts'] as $dirName) {
                self::removeRecursive($siteRoot . DIRECTORY_SEPARATOR . $dirName);
            }

            // 6. 其余文件整体覆盖（保留运行时文件：config.php / data / uploads）
            $mark('apply', 70);
            self::copyTree($srcRoot, $siteRoot, self::preservePaths($siteRoot));

            // 7. 清理临时文件并标记完成；清除版本缓存，下次检查重新拉取
            @unlink($zipFile);
            self::removeRecursive($extractDir);
            $cacheFile = $dataDir . '/version_cache.json';
            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }

            self::writeUpdateStatus(['phase' => 'done', 'title' => 'done', 'percent' => 100, 'version' => $version, 'at' => time()]);
            Response::data(['ok' => true, 'version' => $version]);
        } catch (Throwable $e) {
            self::writeUpdateStatus(['phase' => 'error', 'title' => 'error', 'percent' => 100, 'message' => $e->getMessage(), 'at' => time()]);
            Response::error('更新失败：' . $e->getMessage(), 500, 'UPDATE_FAILED');
        }
    }

    /** 从 GitHub 最新 Release 中挑选可直接部署的产物包（zip 资产） */
    private static function latestReleaseZip(): ?array
    {
        $data = self::httpGet('https://api.github.com/repos/AkibaCat/NuxtWiki/releases/latest');
        if ($data === '') {
            return null;
        }
        $rel = json_decode($data, true);
        if (!is_array($rel)) {
            return null;
        }
        $tag     = (string)($rel['tag_name'] ?? '');
        $version = preg_replace('/^[vV]/', '', trim($tag));
        foreach (($rel['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string)($asset['name'] ?? '');
            $url  = (string)($asset['browser_download_url'] ?? '');
            if ($name !== '' && $url !== '' && str_ends_with(strtolower($name), '.zip')) {
                return ['version' => (string)$version, 'url' => $url];
            }
        }
        return null;
    }

    /** data 目录（更新包与进度文件的存放位置） */
    private static function updateDataDir(): string
    {
        return rtrim((string)(app_config()['uploads']['data_dir'] ?? (__DIR__ . '/../../data')), '/\\');
    }

    private static function updateStatusFile(): string
    {
        return self::updateDataDir() . '/update_status.json';
    }

    private static function readUpdateStatus(): array
    {
        $file = self::updateStatusFile();
        if (is_file($file)) {
            $d = json_decode((string)file_get_contents($file), true);
            if (is_array($d)) {
                return $d;
            }
        }
        return ['phase' => 'idle', 'percent' => 0, 'at' => 0];
    }

    private static function writeUpdateStatus(array $s): void
    {
        $file = self::updateStatusFile();
        $dir  = dirname($file);
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($file, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    /** 站点根目录 = api 目录的上一级（对应生产部署中 index.html/_nuxt/api 所在目录） */
    private static function siteRoot(): string
    {
        $upDir = rtrim((string)(app_config()['uploads']['dir'] ?? ''), '/\\');
        if ($upDir === '') {
            return '';
        }
        $root = dirname(dirname($upDir));
        $real = realpath($root);
        return $real !== false ? $real : $root;
    }

    /** 需要保留（不被更新覆盖）的运行时文件，返回按规范化的绝对路径集合 */
    private static function preservePaths(string $siteRoot): array
    {
        $out = [];
        foreach (['api/config.php', 'api/data', 'api/uploads'] as $rel) {
            $out[] = self::normPath($siteRoot . '/' . $rel);
        }
        return array_values(array_unique($out));
    }

    private static function normPath(string $p): string
    {
        $p = str_replace('\\', '/', $p);
        $p = (string)preg_replace('#/{2,}#', '/', $p);
        return rtrim($p, '/');
    }

    /** 递归删除目录/文件（防止符号链接被误删错删） */
    private static function removeRecursive(string $path): void
    {
        if ($path === '' || $path === '/' || $path === '\\' || $path === DIRECTORY_SEPARATOR) {
            return;
        }
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            if (is_file($path)) @unlink($path);
            return;
        }
        $items = scandir($path);
        if ($items === false) return;
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $p = $path . DIRECTORY_SEPARATOR . $it;
            if (is_dir($p) && !is_link($p)) self::removeRecursive($p);
            else @unlink($p);
        }
        @rmdir($path);
    }

    /** 递归复制目录并覆盖目标，同时跳过需要保留的路径 */
    private static function copyTree(string $src, string $dst, array $skip): void
    {
        if (!is_dir($src)) return;
        if (!is_dir($dst)) @mkdir($dst, 0775, true);
        $items = scandir($src);
        if ($items === false) return;
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $s = $src . DIRECTORY_SEPARATOR . $it;
            $d = $dst . DIRECTORY_SEPARATOR . $it;
            if (in_array(self::normPath($d), $skip, true)) continue;
            if (is_dir($s) && !is_link($s)) {
                self::copyTree($s, $d, $skip);
            } else {
                @copy($s, $d);
            }
        }
    }

    /** 下载二进制文件到指定路径（跟随重定向，cURL 兜底），失败抛异常 */
    private static function httpDownload(string $url, string $dest): void
    {
        $fp = @fopen($dest, 'wb');
        if ($fp === false) {
            throw new RuntimeException('无法创建下载文件。');
        }
        $ctx = stream_context_create(['http' => ['timeout' => 180, 'user_agent' => 'NuxtWiki-Updater/1.0']]);
        $src = @fopen($url, 'rb', false, $ctx);
        if ($src === false) {
            fclose($fp);
            $ok = false;
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 180,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_USERAGENT      => 'NuxtWiki-Updater/1.0',
                ]);
                $raw = curl_exec($ch);
                $err = curl_errno($ch);
                curl_close($ch);
                if ($err === 0 && is_string($raw) && $raw !== '') {
                    @file_put_contents($dest, $raw);
                    $ok = true;
                }
            }
            if (!$ok) {
                @unlink($dest);
                throw new RuntimeException('下载更新包失败：无法连接资源链接。');
            }
            return;
        }
        stream_copy_to_stream($src, $fp);
        fclose($src);
        fclose($fp);
    }

    /** 解压 zip 更新包（依赖 ZipArchive 扩展） */
    private static function unzip(string $zip, string $dest): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('服务器环境缺少 ZipArchive 扩展，无法解压更新包。');
        }
        $za = new ZipArchive();
        if ($za->open($zip) !== true) {
            throw new RuntimeException('更新包损坏，无法解压。');
        }
        $za->extractTo($dest);
        $za->close();
    }

    /** 解压后若存在单一顶层目录（如版本目录），取其作为源根，否则用解压目录本身 */
    private static function detectSourceRoot(string $extractDir): string
    {
        $items = is_dir($extractDir) ? scandir($extractDir) : false;
        if (!is_array($items)) {
            return $extractDir;
        }
        $top = [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $top[] = $it;
        }
        if (count($top) === 1) {
            $only = $extractDir . DIRECTORY_SEPARATOR . $top[0];
            if (is_dir($only)) {
                return $only;
            }
        }
        return $extractDir;
    }
}
