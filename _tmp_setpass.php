<?php
// 设置临时测试密码为 admin123
$p = new PDO('sqlite:' . str_replace('\\', '/', $argv[1]));
$hash = password_hash('admin123', PASSWORD_DEFAULT);
$st = $p->prepare('UPDATE users SET password = ? WHERE username = ?');
$st->execute([$hash, 'admin']);
echo "Updated admin password hash\n";