<?php
$db = new PDO('sqlite:e:\.WindowsData\网站开发\NuxtWiki\api\data\nuxtwiki.sqlite');
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pages = $db->query('SELECT tag, title, revision, substr(body,1,200) AS body FROM pages')->fetchAll();
foreach ($pages as $p) {
    echo "=== {$p['tag']} (rev {$p['revision']}) ===\n";
    echo $p['body'] . "\n";
}
echo "\n--- revisions ---\n";
$revs = $db->query('SELECT page_id, tag, revision, title, comment FROM revisions ORDER BY page_id, revision')->fetchAll();
foreach ($revs as $r) {
    echo "{$r['tag']} r{$r['revision']} :: {$r['comment']}\n";
}
