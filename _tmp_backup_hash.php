<?php
// 备份 admin 密码哈希到 _tmp_admin_hash.txt
$p = new PDO('sqlite:' . str_replace('\\', '/', $argv[1]));
$r = $p->query("SELECT password FROM users WHERE username = 'admin'")->fetch(PDO::FETCH_ASSOC);
file_put_contents(__DIR__ . '/_tmp_admin_hash.txt', $r['password']);
echo "Backed up hash length=" . strlen($r['password']) . "\n";