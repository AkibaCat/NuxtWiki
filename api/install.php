<?php
declare(strict_types=1);

/**
 * NuxtWiki 安装向导
 * 独立脚本：不依赖 config.php / bootstrap.php。
 * GET  → 环境检查 + 安装表单
 * POST → 写入配置、建库、创建管理员
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Shanghai');

$baseDir = __DIR__;
$configFile = $baseDir . '/config.php';

/** 已安装？ */
function installed_state(): string
{
    global $configFile;
    if (!is_file($configFile)) {
        return 'not_installed';
    }
    try {
        $cfg = require $configFile;
        $driver = ($cfg['db']['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
        if ($driver === 'mysql') {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['db']['host'] ?? '127.0.0.1', (int)($cfg['db']['port'] ?? 3306), $cfg['db']['name'] ?? 'nuxtwiki', $cfg['db']['charset'] ?? 'utf8mb4'),
                $cfg['db']['user'] ?? 'root', $cfg['db']['password'] ?? ''
            );
        } else {
            $path = $cfg['db']['sqlite_path'] ?? ($baseDir . '/data/nuxtwiki.sqlite');
            if (!is_dir(dirname($path))) {
                @mkdir(dirname($path), 0777, true);
            }
            $pdo = new PDO('sqlite:' . $path);
        }
        $pdo->query('SELECT COUNT(*) FROM settings');
        return 'installed';
    } catch (Throwable $e) {
        return 'broken';
    }
}

/** 环境检查 */
function requirements(): array
{
    $checks = [];
    $add = fn(string $name, bool $ok, string $detail = '') => $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];

    $add('PHP 版本 ≥ 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
    $add('PDO 扩展', extension_loaded('pdo'), '');
    $add('PDO_SQLITE（本地开发）', extension_loaded('pdo_sqlite'), '');
    $add('PDO_MYSQL（生产环境）', extension_loaded('pdo_mysql'), '');
    $add('mbstring', extension_loaded('mbstring'), '');
    $add('JSON', extension_loaded('json'), '');
    $add('GD（缩略图，可选）', extension_loaded('gd'), '');

    global $baseDir;
    $add('api 目录可写', is_writable($baseDir), $baseDir);
    foreach (['data', 'uploads'] as $sub) {
        $p = $baseDir . '/' . $sub;
        if (!is_dir($p)) {
            @mkdir($p, 0777, true);
        }
        $add($sub . ' 目录可写', is_writable($p), $p);
    }
    return $checks;
}

// ---------- 处理请求 ----------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$state = installed_state();

if ($method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if ($state === 'installed' && empty($_POST['force'])) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => ['code' => 'ALREADY_INSTALLED', 'message' => 'NuxtWiki 已安装。']]);
        exit;
    }

    $driver = ($_POST['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
    $siteName = trim((string)($_POST['site_name'] ?? 'NuxtWiki'));
    $language = in_array(($_POST['language'] ?? 'zh-CN'), ['zh-CN', 'zh-TW', 'en'], true) ? $_POST['language'] : 'zh-CN';
    $homeTag  = trim((string)($_POST['home_tag'] ?? 'HomePage'));
    $homeTag  = preg_replace('/[^\p{L}\p{N}_\-\.]+/u', '_', str_replace(' ', '_', $homeTag)) ?? $homeTag;
    $adminUser = trim((string)($_POST['admin_user'] ?? 'Admin'));
    $adminPass = (string)($_POST['admin_pass'] ?? '');

    if (strlen($adminPass) < 6) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => ['code' => 'INVALID_INPUT', 'message' => '管理员密码至少 6 位。']]);
        exit;
    }

    $dbConf = ['driver' => 'sqlite', 'sqlite_path' => $baseDir . '/data/nuxtwiki.sqlite'];
    if ($driver === 'mysql') {
        $dbConf = [
            'driver'   => 'mysql',
            'host'     => trim((string)($_POST['db_host'] ?? 'localhost')),
            'port'     => (int)($_POST['db_port'] ?? 3306),
            'name'     => trim((string)($_POST['db_name'] ?? 'nuxtwiki')),
            'user'     => trim((string)($_POST['db_user'] ?? 'root')),
            'password' => (string)($_POST['db_password'] ?? ''),
            'charset'  => 'utf8mb4',
            'sqlite_path' => $baseDir . '/data/nuxtwiki.sqlite',
        ];
    }

    try {
        // 1) 连接并执行 schema
        $pdo = db_connect($dbConf, $driver);
        $schemaFile = $driver === 'mysql' ? $baseDir . '/schema.mysql.sql' : $baseDir . '/schema.sqlite.sql';
        $sql = (string)file_get_contents($schemaFile);
        $pdo->exec($sql);

        // 1.1) 清空旧数据（schema 为 CREATE TABLE IF NOT EXISTS，重复安装时需先清表）
        $tables = ['regcodes', 'watchers', 'revisions', 'pages', 'users', 'settings'];
        foreach ($tables as $t) {
            $pdo->exec('DELETE FROM ' . $t);
            if ($driver === 'mysql') {
                $pdo->exec('ALTER TABLE ' . $t . ' AUTO_INCREMENT = 1');
            }
        }
        if ($driver === 'sqlite') {
            $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('users','pages','revisions','watchers','regcodes')");
        }

        // 2) 写入初始设置
        $now = date('Y-m-d H:i:s');
        $settings = [
            'site_name' => $siteName, 'site_description' => '一个基于 Nuxt UI 与 PHP/MySQL 的轻量 Wiki。',
            'site_footer' => '', 'home_tag' => $homeTag, 'language' => $language,
            'allow_registration' => '1',
            'default_read_level' => '0', 'default_edit_level' => '3', 'default_history_level' => '3',
            'default_diff_level' => '2', 'default_backlinks_level' => '3', 'default_perms_level' => '1',
            'default_contributors_level' => '0',
        ];
        $st = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)');
        foreach ($settings as $k => $v) {
            $st->execute([$k, $v]);
        }

        // 3) 创建管理员
        $st = $pdo->prepare('INSERT INTO users (username, password, is_admin, level, created_at, updated_at) VALUES (?, ?, 1, 1, ?, ?)');
        $st->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $now, $now]);

        // 4) 生成初始页面：按安装选择的语言读取对应语言的种子文件
        //    (seed/<name>.md 为简体中文；语言版为 seed/<name>.<lang>.md)
        $seedI18n = [
            'zh-CN' => ['homeTitle' => "欢迎使用 $siteName", 'helpTitle' => '语法帮助', 'comment' => '初始页面'],
            'zh-TW' => ['homeTitle' => "歡迎使用 $siteName", 'helpTitle' => '語法幫助', 'comment' => '初始頁面'],
            'en'    => ['homeTitle' => "Welcome to $siteName", 'helpTitle' => 'Grammar Help', 'comment' => 'Initial page'],
        ];
        $si = $seedI18n[$language] ?? $seedI18n['zh-CN'];
        $seed = static function (string $name) use ($baseDir, $language): string {
            $alt = "$baseDir/seed/$name.$language.md";
            return is_file($alt) ? $alt : "$baseDir/seed/$name.md";
        };

        // 首页（站点名通过 {{SITE_NAME}} 占位符注入）
        $pageId = (int)$pdo->lastInsertId();
        $homeBody = str_replace('{{SITE_NAME}}', $siteName, (string)file_get_contents($seed('welcome')));
        $st = $pdo->prepare('INSERT INTO pages (tag, title, body, created_at, updated_at, revision, acl_read, acl_edit, acl_history, acl_diff, acl_backlinks, acl_acl, acl_contributors) VALUES (?, ?, ?, ?, ?, 1, \'0\', \'3\', \'3\', \'2\', \'3\', \'1\', \'0\')');
        $st->execute([$homeTag, $si['homeTitle'], $homeBody, $now, $now]);
        $st = $pdo->prepare('INSERT INTO revisions (page_id, tag, title, body, comment, user_id, revision, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
        $st->execute([$pageId, $homeTag, $si['homeTitle'], $homeBody, $si['comment'], null, $now]);

        // 语法教学页
        $helpTag = 'GrammarHelp';
        $helpTitle = $si['helpTitle'];
        $helpBody = (string)file_get_contents($seed('grammar-help'));
        $st = $pdo->prepare('INSERT INTO pages (tag, title, body, created_at, updated_at, revision, acl_read, acl_edit, acl_history, acl_diff, acl_backlinks, acl_acl, acl_contributors) VALUES (?, ?, ?, ?, ?, 1, \'0\', \'3\', \'3\', \'2\', \'3\', \'1\', \'0\')');
        $st->execute([$helpTag, $helpTitle, $helpBody, $now, $now]);
        $helpId = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('INSERT INTO revisions (page_id, tag, title, body, comment, user_id, revision, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
        $st->execute([$helpId, $helpTag, $helpTitle, $helpBody, $si['comment'], null, $now]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => ['code' => 'INSTALL_FAILED', 'message' => '安装失败: ' . $e->getMessage()]]);
        exit;
    }

    // 5) 写入 config.php
    $full = [
        'db' => $dbConf,
        'site' => [
            'name' => $siteName, 'description' => '一个基于 Nuxt UI 与 PHP/MySQL 的轻量 Wiki。',
            'base_url' => '', 'home_tag' => $homeTag, 'language' => $language,
            'allow_registration' => true,
            'default_read_level' => '0', 'default_edit_level' => '3', 'default_history_level' => '3',
            'default_diff_level' => '2', 'default_backlinks_level' => '3', 'default_perms_level' => '1',
            'default_contributors_level' => '0',
        ],
        'mail' => ['from' => 'wiki@example.com', 'transport' => 'mail', 'smtp' => ['host' => '', 'port' => 465, 'user' => '', 'password' => '', 'encryption' => 'ssl']],
        'security' => ['session_name' => 'NUXTWIKI', 'session_lifetime' => 7 * 24 * 3600],
        'uploads' => ['dir' => $baseDir . '/uploads', 'data_dir' => $baseDir . '/data', 'max_size' => 32 * 1024 * 1024, 'thumb_max' => 320,
            'allowed' => ['jpg','jpeg','png','gif','webp','svg','bmp','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','md','csv','json','xml','zip','rar','7z','tar','gz','mp3','mp4','webm','ogg']],
    ];
    $php = "<?php\n/** NuxtWiki API 配置（由安装向导生成） */
    
    return " . var_export($full, true) . ";\n";
    if (@file_put_contents($configFile, $php, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => ['code' => 'WRITE_FAILED', 'message' => '无法写入 config.php，请检查目录权限。']]);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => ['installed' => true, 'site_name' => $siteName, 'home_tag' => $homeTag]]);
    exit;
}

// ---------- GET：环境检查 + 安装表单 ----------
$checks = requirements();
$allOk = !in_array(false, array_column($checks, 'ok'), true);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NuxtWiki 安装向导</title>
<style>
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#f6f8fa;margin:0;padding:32px 16px;color:#1f2937}
  .wrap{max-width:720px;margin:0 auto}
  h1{font-size:22px;margin:0 0 4px}
  .sub{color:#6b7280;margin:0 0 24px;font-size:14px}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px 24px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
  .card h2{font-size:16px;margin:0 0 12px}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #f0f0f0}
  .ok{color:#059669;font-weight:600}
  .no{color:#dc2626;font-weight:600}
  label{display:block;font-size:13px;color:#374151;margin:12px 0 4px}
  input,select{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box}
  input:focus,select:focus{outline:2px solid #00dc82;outline-offset:1px;border-color:#00dc82}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}
  button{margin-top:20px;width:100%;padding:12px;background:#00c16a;color:#fff;border:0;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer}
  button:hover{background:#00a155}
  button:disabled{background:#9ca3af;cursor:not-allowed}
  .hint{font-size:12px;color:#9ca3af;margin-top:4px}
  .banner{border-left:4px solid #f59e0b;background:#fffbeb;padding:10px 14px;border-radius:6px;font-size:14px}
  .banner.err{border-left-color:#dc2626;background:#fef2f2}
  .ok-banner{border-left:4px solid #059669;background:#ecfdf5;padding:10px 14px;border-radius:6px;font-size:14px}
</style>
</head>
<body>
<div class="wrap">
  <h1>NuxtWiki 安装向导</h1>
  <p class="sub">在 Kangle + MySQL + PHP 环境下部署，或本地使用 SQLite 快速开始。</p>

  <?php if ($state === 'installed'): ?>
    <div class="card"><div class="ok-banner">✓ NuxtWiki 已安装。如需重新安装，请直接删除或修改 <code>api/config.php</code>。</div></div>
  <?php endif; ?>

  <?php if ($state === 'broken'): ?>
    <div class="card"><div class="banner err">检测到 config.php 存在但无法连接数据库。请检查数据库配置后重试。</div></div>
  <?php endif; ?>

  <div class="card">
    <h2>环境检查</h2>
    <table>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['name']) ?></td>
          <td class="<?= $c['ok'] ? 'ok' : 'no' ?>"><?= $c['ok'] ? '✓ 通过' : '✗ 缺失' ?></td>
          <td style="color:#9ca3af"><?= htmlspecialchars((string)$c['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <form method="post" onsubmit="return onInstall(this)">
    <div class="card">
      <h2>数据库</h2>
      <label>数据库类型</label>
      <select id="driver" name="driver" onchange="toggleDb()">
        <option value="sqlite">SQLite（本地开发 / 测试，零配置）</option>
        <option value="mysql">MySQL（Kangle 生产环境）</option>
      </select>
      <div id="mysql-fields" style="display:none">
        <div class="row">
          <div><label>主机</label><input name="db_host" value="localhost"></div>
          <div><label>端口</label><input name="db_port" value="3306"></div>
        </div>
        <div class="row">
          <div><label>数据库名</label><input name="db_name" value="nuxtwiki"></div>
          <div><label>用户名</label><input name="db_user" value="root"></div>
        </div>
        <label>密码</label><input type="password" name="db_password">
      </div>
    </div>
    <div class="card">
      <h2>站点设置</h2>
      <label>站点名称</label><input name="site_name" value="NuxtWiki" required>
      <label>界面语言（首次访问站点的默认显示语言）</label>
      <select name="language" id="language">
        <option value="zh-CN" selected>简体中文</option>
        <option value="zh-TW">繁體中文</option>
        <option value="en">English</option>
      </select>
      <label>首页名</label><input name="home_tag" value="Home" required>
    </div>
    <div class="card">
      <h2>管理员账号</h2>
      <div class="row">
        <div><label>用户名</label><input name="admin_user" value="admin" required></div>
        <div><label>密码（至少 6 位）</label><input type="password" name="admin_pass" required></div>
      </div>
    </div>
    <button id="install-btn" <?= $allOk && $state !== 'installed' ? '' : 'disabled' ?>><?= $state === 'installed' ? '重新安装' : '开始安装' ?></button>
  </form>
  <p class="hint" style="text-align:center">安装完成后将生成 api/config.php，请妥善保管数据库凭据。</p>
</div>
<script>
function toggleDb(){
  var v = document.getElementById('driver').value;
  document.getElementById('mysql-fields').style.display = v === 'mysql' ? '' : 'none';
}
function onInstall(form){
  var btn = document.getElementById('install-btn');
  btn.disabled = true; btn.textContent = '安装中…';
  var data = new FormData(form);
  fetch('install.php', {method:'POST', body:data})
    .then(function(r){return r.json().then(function(j){return {r:r, j:j}})})
    .then(function(o){
      if(o.j.ok){
        btn.textContent = '✓ 安装成功';
        setTimeout(function(){ location.href = '../'; }, 1200);
      } else {
        alert(o.j.error && o.j.error.message || '安装失败');
        btn.disabled = false; btn.textContent = '重试';
      }
    })
    .catch(function(){
      alert('请求失败，请检查 PHP 环境。');
      btn.disabled = false; btn.textContent = '重试';
    });
  return false;
}
</script>
</body>
</html>

<?php
/** 建立 PDO 连接（供安装过程使用） */
function db_connect(array $dbConf, string $driver): PDO
{
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if ($driver === 'mysql') {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $dbConf['host'], $dbConf['port'], $dbConf['name'], $dbConf['charset']),
            $dbConf['user'], $dbConf['password'], $opts
        );
    }
    $path = $dbConf['sqlite_path'];
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0777, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, $opts);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}
