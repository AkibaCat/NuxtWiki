<?php
// 临时探测脚本：列出本地测试库用户（不含密码哈希明细）
$p = new PDO('sqlite:' . str_replace('\\', '/', $argv[1]));
echo "== users ==\n";
foreach ($p->query('SELECT id, username, nickname, level, status, avatar, email FROM users') as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
echo "== pages ==\n";
foreach ($p->query('SELECT id, tag, title, revision FROM pages ORDER BY id LIMIT 30') as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
echo "== settings keys ==\n";
foreach ($p->query("SELECT skey, svalue FROM settings") as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
