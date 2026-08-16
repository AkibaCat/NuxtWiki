<?php
declare(strict_types=1);

/** RSS 订阅：最近更改 / 最近评论 */
final class FeedController
{
    /** GET feed.rss?limit= —— 最近更改 RSS 2.0 */
    public static function rss(): never
    {
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $st = Database::pdo()->prepare(
            'SELECT r.' . Database::qi('tag') . ', r.' . Database::qi('title') . ', r.' . Database::qi('comment') . ', '
            . 'r.' . Database::qi('created_at') . ', u.' . Database::qi('username')
            . ' FROM ' . Database::qi('revisions') . ' r'
            . ' LEFT JOIN ' . Database::qi('users') . ' u ON u.' . Database::qi('id') . ' = r.' . Database::qi('user_id')
            . ' ORDER BY r.' . Database::qi('id') . ' DESC LIMIT ' . (int)$limit
        );
        $st->execute();
        $items = $st->fetchAll();

        $base = Settings::baseUrl();
        $name = htmlspecialchars(Settings::siteName(), ENT_XML1, 'UTF-8');
        $desc = htmlspecialchars(Settings::siteDescription(), ENT_XML1, 'UTF-8');
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '<title>' . $name . ' - 最近更改</title>' . "\n";
        $xml .= '<link>' . $base . '</link>' . "\n";
        $xml .= '<description>' . $desc . '</description>' . "\n";
        $xml .= '<atom:link href="' . $base . '/api/index.php?r=feed.rss" rel="self" type="application/rss+xml"/>' . "\n";
        $xml .= '<lastBuildDate>' . gmdate('D, d M Y H:i:s', time()) . ' GMT</lastBuildDate>' . "\n";

        foreach ($items as $r) {
            $url = $base . '/' . rawurlencode((string)$r['tag']);
            $title = htmlspecialchars((string)$r['title'], ENT_XML1, 'UTF-8');
            $comment = htmlspecialchars((string)$r['comment'], ENT_XML1, 'UTF-8');
            $author = $r['username'] !== null ? htmlspecialchars((string)$r['username'], ENT_XML1, 'UTF-8') : '匿名';
            $pub = gmdate('D, d M Y H:i:s', strtotime((string)$r['created_at'])) . ' GMT';
            $xml .= '<item>' . "\n";
            $xml .= '<title>' . $title . '</title>' . "\n";
            $xml .= '<link>' . $url . '</link>' . "\n";
            $xml .= '<guid isPermaLink="true">' . $url . '</guid>' . "\n";
            $xml .= '<description>' . ($comment !== '' ? '<![CDATA[' . $comment . ']]>' : '') . '</description>' . "\n";
            $xml .= '<author>' . $author . '</author>' . "\n";
            $xml .= '<pubDate>' . $pub . '</pubDate>' . "\n";
            $xml .= '</item>' . "\n";
        }
        $xml .= '</channel>' . "\n" . '</rss>' . "\n";
        Response::xml($xml);
    }
}
