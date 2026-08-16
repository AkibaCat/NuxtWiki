<?php
// NuxtWiki 冒烟测试（UTF-8 安全）—— 本地验证用，验证后删除
declare(strict_types=1);

const BASE = 'http://localhost:8765/index.php';

$cookie = '';

function call(string $url, string $method = 'GET', ?string $json = null, ?string $csrf = null, ?string &$cookie = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $json,
    ]);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    preg_match('/^Set-Cookie:\s*([^;]+)/mi', (string)curl_getinfo($ch, CURLINFO_HEADER_OUT), $m);
    // 手动收集 cookie
    $hdr = curl_getinfo($ch, CURLINFO_HEADER_OUT) . "\n" . (string)curl_exec($ch);
    curl_close($ch);
    // 用独立请求取 cookie 不可行；改为让调用方传入
    $out = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => $out];
}

function check(string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n";
}

// 使用 curl 会话（自动管理 cookie）
$ch = curl_init();
function req(string $url, string $method = 'GET', ?string $json = null, ?string $csrf = null): array
{
    global $ch;
    $headers = ['Accept: application/json'];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_COOKIEJAR      => __DIR__ . '/_cookies.txt',
        CURLOPT_COOKIEFILE     => __DIR__ . '/_cookies.txt',
        CURLOPT_HEADER         => false,
    ]);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $json = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => $json];
}

$r = req(BASE . '?r=auth.me');
check('auth.me', ($r['json']['ok'] ?? false) === true, $r['body']);

$r = req(BASE . '?r=auth.login', 'POST', json_encode(['username' => 'admin', 'password' => 'secret123'], JSON_UNESCAPED_UNICODE));
$csrf = $r['json']['data']['csrf'] ?? '';
check('auth.login', ($r['json']['ok'] ?? false) === true && ($r['json']['data']['user']['is_admin'] ?? false) === true, $r['body']);

// 保存中文页面
$body = "=页面标题=\n\n**加粗**与//斜体//，链接 [[HomePage]]。\n\n==子标题==\n* 项目一\n* 项目二\n\n%%%\n$code = 1;\n%%%\n\n{{图片占位}}";
$r = req(BASE . '?r=page.save', 'POST', json_encode(['tag' => '测试页', 'title' => '测试页面', 'body' => $body, 'comment' => '创建测试页'], JSON_UNESCAPED_UNICODE), $csrf);
check('page.save(create)', ($r['json']['ok'] ?? false) === true && ($r['json']['data']['created'] ?? false) === true, $r['body']);

$r = req(BASE . '?r=page.get&tag=' . rawurlencode('测试页'));
check('page.get', ($r['json']['ok'] ?? false) === true && ($r['json']['data']['page']['title'] ?? '') === '测试页面', $r['body']);

// 第二次保存 → 修订 + diff
$r = req(BASE . '?r=page.save', 'POST', json_encode(['tag' => '测试页', 'title' => '测试页面改', 'body' => $body . "\n\n新增一行内容。", 'comment' => '再次编辑'], JSON_UNESCAPED_UNICODE), $csrf);
check('page.save(update)', ($r['json']['data']['created'] ?? true) === false && ($r['json']['data']['revision'] ?? 0) === 2, $r['body']);

$r = req(BASE . '?r=page.diff&tag=' . rawurlencode('测试页') . '&from=1&to=2');
$lines = $r['json']['data']['lines'] ?? [];
check('page.diff', is_array($lines) && count($lines) > 0, $r['body']);

$r = req(BASE . '?r=page.revisions&tag=' . rawurlencode('测试页'));
check('page.revisions', count($r['json']['data'] ?? []) === 2, $r['body']);

$r = req(BASE . '?r=page.search&q=' . rawurlencode('加粗'));
check('page.search', ($r['json']['data']['total'] ?? 0) > 0, $r['body']);

$r = req(BASE . '?r=page.backlinks&tag=HomePage');
check('page.backlinks', count($r['json']['data'] ?? []) >= 1, $r['body']);

$r = req(BASE . '?r=page.similar&tag=' . rawurlencode('测试页'));
check('page.similar', is_array($r['json']['data'] ?? null), $r['body']);

$r = req(BASE . '?r=comments.add', 'POST', json_encode(['page_id' => 1, 'body' => '这是一条中文评论。'], JSON_UNESCAPED_UNICODE), $csrf);
check('comments.add', ($r['json']['ok'] ?? false) === true, $r['body']);

$r = req(BASE . '?r=comments.list&page_id=1');
check('comments.list', count($r['json']['data'] ?? []) >= 1 && str_contains($r['body'], '中文评论'), $r['body']);

$r = req(BASE . '?r=watch.add', 'POST', json_encode(['tag' => 'HomePage'], JSON_UNESCAPED_UNICODE), $csrf);
check('watch.add', ($r['json']['data']['watching'] ?? false) === true, $r['body']);

$r = req(BASE . '?r=watch.list');
check('watch.list', count($r['json']['data'] ?? []) === 1, $r['body']);

$r = req(BASE . '?r=admin.stats');
$d = $r['json']['data'] ?? [];
check('admin.stats', ($d['pages'] ?? 0) >= 2, $r['body']);

$r = req(BASE . '?r=feed.rss&limit=5');
check('feed.rss', str_contains($r['body'], '<rss') && str_contains($r['body'], '测试页面'), $r['body']);

$r = req(BASE . '?r=page.update-acl', 'POST', json_encode(['tag' => '测试页', 'acl_read' => '0', 'acl_edit' => '3', 'acl_history' => '3', 'acl_diff' => '2', 'acl_backlinks' => '3', 'acl_acl' => '1', 'acl_contributors' => '0'], JSON_UNESCAPED_UNICODE), $csrf);
check('page.update-acl', ($r['json']['ok'] ?? false) === true, $r['body']);

$r = req(BASE . '?r=page.perms&tag=' . rawurlencode('测试页'));
check('page.perms', ($r['json']['data']['acl_edit'] ?? '') === '3', $r['body']);

@unlink(__DIR__ . '/_cookies.txt');
echo "DONE\n";
