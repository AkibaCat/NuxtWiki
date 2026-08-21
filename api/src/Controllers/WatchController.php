<?php
declare(strict_types=1);

/** 页面订阅（Watch）：状态 / 添加 / 取消 / 我的订阅 */
final class WatchController
{
    /** GET watch.status?tag= */
    public static function status(): never
    {
        $user = Auth::user();
        if ($user === null) {
            Response::data(['watching' => false]);
        }
        $tag = Text::normalizeTag((string)($_GET['tag'] ?? ''));
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM ' . Database::qi('watchers') . ' w'
            . ' JOIN ' . Database::qi('pages') . ' p ON p.' . Database::qi('id') . ' = w.' . Database::qi('page_id')
            . ' WHERE p.' . Database::qi('tag') . ' = ? AND w.' . Database::qi('user_id') . ' = ?'
        );
        $st->execute([$tag, (int)$user['id']]);
        Response::data(['watching' => (int)$st->fetchColumn() > 0]);
    }

    /** POST watch.add */
    public static function add(): never
    {
        Auth::verifyCsrf();
        $user = Auth::requireLogin();
        $b = Response::body();
        $tag = Text::normalizeTag((string)($b['tag'] ?? ''));
        $st = Database::pdo()->prepare('SELECT ' . Database::qi('id') . ' FROM ' . Database::qi('pages') . ' WHERE ' . Database::qi('tag') . ' = ?');
        $st->execute([$tag]);
        $pageId = $st->fetchColumn();
        if ($pageId === false) {
            Response::error('页面不存在。', 404, 'NOT_FOUND');
        }
        // MySQL 用 INSERT IGNORE，SQLite 用 INSERT OR IGNORE（不可先 prepare 另一方言）
        $sql = Database::driver() === 'mysql'
            ? 'INSERT IGNORE INTO ' . Database::qi('watchers') . ' (' . Database::qi('page_id') . ', ' . Database::qi('user_id') . ', ' . Database::qi('created_at') . ') VALUES (?, ?, ?)'
            : 'INSERT OR IGNORE INTO ' . Database::qi('watchers') . ' (' . Database::qi('page_id') . ', ' . Database::qi('user_id') . ', ' . Database::qi('created_at') . ') VALUES (?, ?, ?)';
        $st = Database::pdo()->prepare($sql);
        $st->execute([(int)$pageId, (int)$user['id'], Database::now()]);
        Response::data(['watching' => true]);
    }

    /** POST watch.remove */
    public static function remove(): never
    {
        Auth::verifyCsrf();
        $user = Auth::requireLogin();
        $b = Response::body();
        $tag = Text::normalizeTag((string)($b['tag'] ?? ''));
        $st = Database::pdo()->prepare('SELECT ' . Database::qi('id') . ' FROM ' . Database::qi('pages') . ' WHERE ' . Database::qi('tag') . ' = ?');
        $st->execute([$tag]);
        $pageId = $st->fetchColumn();
        if ($pageId === false) {
            Response::data(['watching' => false]);
        }
        $st = Database::pdo()->prepare('DELETE FROM ' . Database::qi('watchers') . ' WHERE ' . Database::qi('page_id') . ' = ? AND ' . Database::qi('user_id') . ' = ?');
        $st->execute([(int)$pageId, (int)$user['id']]);
        Response::data(['watching' => false]);
    }

    /** GET watch.list —— 我订阅的页面 */
    public static function list(): never
    {
        $user = Auth::requireLogin();
        $st = Database::pdo()->prepare(
            'SELECT p.' . Database::qi('tag') . ', p.' . Database::qi('title') . ', p.' . Database::qi('updated_at') . ', '
            . 'p.' . Database::qi('revision') . ', w.' . Database::qi('created_at')
            . ' FROM ' . Database::qi('watchers') . ' w'
            . ' JOIN ' . Database::qi('pages') . ' p ON p.' . Database::qi('id') . ' = w.' . Database::qi('page_id')
            . ' WHERE w.' . Database::qi('user_id') . ' = ? ORDER BY w.' . Database::qi('id') . ' DESC'
        );
        $st->execute([(int)$user['id']]);
        Response::data(array_map(fn($r) => [
            'tag'        => (string)$r['tag'],
            'title'      => (string)$r['title'],
            'revision'   => (int)$r['revision'],
            'updated_at' => (string)$r['updated_at'],
            'subscribed_at' => (string)$r['created_at'],
        ], $st->fetchAll()));
    }
}
