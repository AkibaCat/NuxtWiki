<?php
$pdo = new PDO('sqlite:' . __DIR__ . '/api/data/nuxtwiki.sqlite');
echo "---pages---\n";
foreach ($pdo->query('SELECT tag,title FROM pages LIMIT 20') as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
}
