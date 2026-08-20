<?php
declare(strict_types=1);

/** 页面：查看 / 编辑 / 修订 / diff / 搜索 / 回链 / 权限 */
final class PageController
{
    /** 首页 */
    public static function index(): never
    {
        $_GET['tag'] = Settings::homeTag();
        self::get();
    }

    /** GET page.get?tag= */
    public static function get(): never
    {
        $tag = Text::normalizeTag((string)($_GET['tag'] ?? ''));
        if ($tag === '') {
            Response::error('缺少页面名。', 422, 'INVALID_INPUT');
        }
        $page = self::find($tag);
        $user = Auth::user();

        // 未创建页面：始终允许读取元信息，交由前端决定是否提示创建
        if ($page === null) {
            Response::data([
                'exists'          => false,
                'tag'             => $tag,
                'can_read'        => true,
                'can_edit'        => Auth::canLevel((int)Settings::defaultLevel('edit'), $user),
                'can_history'     => Auth::canLevel((int)Settings::defaultLevel('history'), $user),
                'can_diff'        => Auth::canLevel((int)Settings::defaultLevel('diff'), $user),
                'can_backlinks'   => Auth::canLevel((int)Settings::defaultLevel('backlinks'), $user),
                'can_acl'         => Auth::canLevel((int)Settings::defaultLevel('perms'), $user),
                'can_contributors'=> Auth::canLevel((int)Settings::defaultLevel('contributors'), $user),
                'watching'        => false,
            ]);
        }

        if (!Auth::canLevel((int)($page['acl_read'] ?? 0), $user)) {
            Response::error('你没有权限阅读该页面。', 403, 'FORBIDDEN');
        }

        // 阅读计数
        $st = Database::pdo()->prepare('UPDATE ' . Database::qi('pages') . ' SET ' . Database::qi('hits') . ' = ' . Database::qi('hits') . ' + 1 WHERE id = ?');
        $st->execute([(int)$page['id']]);
        $page['hits'] = (int)$page['hits'] + 1;

        Response::data([
            'exists'          => true,
            'page'            => self::payload($page),
            'can_read'        => Auth::canLevel((int)($page['acl_read'] ?? 0), $user),
            'can_edit'        => Auth::canLevel((int)($page['acl_edit'] ?? 3), $user),
            'can_history'     => Auth::canLevel((int)($page['acl_history'] ?? 3), $user),
            'can_diff'        => Auth::canLevel((int)($page['acl_diff'] ?? 2), $user),
            'can_backlinks'   => Auth::canLevel((int)($page['acl_backlinks'] ?? 3), $user),
            'can_acl'         => Auth::canLevel((int)($page['acl_acl'] ?? 1), $user),
            'can_contributors'=> Auth::canLevel((int)($page['acl_contributors'] ?? 0), $user),
            'watching'        => $user !== null && self::isWatching((int)$page['id'], (int)$user['id']),
            'is_admin'        => Auth::isAdmin(),
            'contributors'    => self::contributors((int)$page['id']),
            'subscriber_count'=> self::subscriberCount((int)$page['id']),
        ]);
    }

    /** GET page.list —— 全部页面 */
    public static function list(): never
    {
        $rows = Database::pdo()->query(
            'SELECT tag, title, ' . Database::qi('hits') . ', ' . Database::qi('updated_at') . ' FROM ' . Database::qi('pages') . ' ORDER BY tag'
        )->fetchAll();
        Response::data(array_map(fn($r) => [
            'tag'        => (string)$r['tag'],
            'title'      => (string)$r['title'],
            'hits'       => (int)$r['hits'],
            'updated_at' => (string)$r['updated_at'],
        ], $rows));
    }

    /** GET page.recent?limit=&days= —— 最近更改（基于修订） */
    public static function recent(): never
    {
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $days  = (int)($_GET['days'] ?? 0);
        $sql = 'SELECT r.' . Database::qi('tag') . ', r.' . Database::qi('title') . ', r.' . Database::qi('revision') . ', '
             . 'r.' . Database::qi('comment') . ', r.' . Database::qi('created_at') . ', u.' . Database::qi('username') . ', u.' . Database::qi('nickname')
             . ' FROM ' . Database::qi('revisions') . ' r'
             . ' LEFT JOIN ' . Database::qi('users') . ' u ON u.' . Database::qi('id') . ' = r.' . Database::qi('user_id');
        $params = [];
        if ($days > 0) {
            $sql .= ' WHERE r.' . Database::qi('created_at') . ' >= ?';
            $params[] = date('Y-m-d H:i:s', time() - $days * 86400);
        }
        $sql .= ' ORDER BY r.' . Database::qi('id') . ' DESC LIMIT ' . (int)$limit;
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        Response::data(array_map(fn($r) => [
            'tag'        => (string)$r['tag'],
            'title'      => (string)$r['title'],
            'revision'   => (int)$r['revision'],
            'comment'    => (string)$r['comment'],
            'username'   => $r['username'] !== null ? (string)$r['username'] : null,
            'nickname'   => $r['nickname'] !== null ? (string)$r['nickname'] : null,
            'created_at' => (string)$r['created_at'],
        ], $st->fetchAll()));
    }

    /** GET page.search?q=&offset=&limit= */
    public static function search(): never
    {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') {
            Response::data(['total' => 0, 'results' => []]);
        }
        $limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $like = '%' . $q . '%';

        $countSt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM ' . Database::qi('pages') . ' WHERE ' . Database::qi('tag') . ' LIKE ? OR '
            . Database::qi('title') . ' LIKE ? OR ' . Database::qi('body') . ' LIKE ?'
        );
        $countSt->execute([$like, $like, $like]);
        $total = (int)$countSt->fetchColumn();

        $st = Database::pdo()->prepare(
            'SELECT ' . Database::qi('tag') . ', ' . Database::qi('title') . ', ' . Database::qi('body') . ', '
            . Database::qi('updated_at') . ', ' . Database::qi('revision') . ' FROM ' . Database::qi('pages')
            . ' WHERE ' . Database::qi('tag') . ' LIKE ? OR ' . Database::qi('title') . ' LIKE ? OR ' . Database::qi('body') . ' LIKE ?'
            . ' ORDER BY ' . Database::qi('tag') . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
        );
        $st->execute([$like, $like, $like]);
        $results = [];
        foreach ($st->fetchAll() as $r) {
            $results[] = [
                'tag'        => (string)$r['tag'],
                'title'      => (string)$r['title'],
                'snippet'    => self::snippet((string)$r['body'], $q),
                'updated_at' => (string)$r['updated_at'],
                'revision'   => (int)$r['revision'],
            ];
        }
        Response::data(['total' => $total, 'results' => $results]);
    }

    /** GET page.revisions?tag= */
    public static function revisions(): never
    {
        $tag = Text::normalizeTag((string)($_GET['tag'] ?? ''));
        $page = self::find($tag);
        if ($page === null) {
            Response::error('页面不存在。', 404, 'NOT_FOUND');
        }
        $user = Auth::user();
        if (!Auth::canLevel((int)($page['acl_history'] ?? 3), $user)) {
            Response::error('你没有查看该页面历史的权限。', 403, 'FORBIDDEN');
        }
        $st = Database::pdo()->prepare(
            'SELECT r.' . Database::qi('revision') . ', r.' . Database::qi('title') . ', r.' . Database::qi('comment') . ', '
            . 'r.' . Database::qi('created_at') . ', u.' . Database::qi('username') . ', u.' . Database::qi('nickname')
            . ' FROM ' . Database::qi('revisions') . ' r'
            . ' LEFT JOIN ' . Database::qi('users') . ' u ON u.' . Database::qi('id') . ' = r.' . Database::qi('user_id')
            . ' WHERE r.' . Database::qi('page_id') . ' = ? ORDER BY r.' . Database::qi('revision') . ' DESC'
        );
        $st->execute([(int)$page['id']]);
        Response::data(array_map(fn($r) => [
            'revision'   => (int)$r['revision'],
            'title'      => (string)$r['title'],
            'comment'    => (string)$r['comment'],
            'username'   => $r['username'] !== null ? (string)$r['username'] : null,
            'nickname'   => $r['nickname'] !== null ? (string)$r['nickname'] : null,
            'created_at' => (string)$r['created_at'],
        ], $st->fetchAll()));
    }

    /** GET page.diff?tag=&from=&to= （缺省时对比上一版） */
    public static function diff(): never
    {
        $tag = Text::normalizeTag((string)($_GET['tag'] ?? ''));
        $page = self::find($tag);
        if ($page === null) {
            Response::error('页面不存在。', 404, 'NOT_FOUND');
        }
        $user = Auth::user();
        if (!Auth::canLevel((int)($page['acl_diff'] ?? 2), $user)) {
            Response::error('没有对比该页面版本的权限。', 403, 'FORBIDDEN');
        }

        $from = (int)($_GET['from'] ?? 0);
        $to   = (int)($_GET['to'] ?? (int)$page['revision']);
        $revs = self::revisionBodies((int)$page['id']);
        if (!isset($revs[$to])) {
            Response::error('目标版本不存在。', 404, 'NOT_FOUND');
        }
        if ($from === 0) {
            // 与前一个版本比较
            $keys = array_keys($revs);
            $idx = array_search($to, $keys, true);
            $from = ($idx > 0) ? (int)$keys[$idx - 1] : 0;
        }
        $old = isset($revs[$from]) ? $revs[$from]['body'] : '';
        $new = $revs[$to]['body'];

        Response::data([
            'tag'  => $tag,
            'from' => $from,
            'to'   => $to,
            'from_meta' => $revs[$from] ?? null,
            'to_meta'   => $revs[$to],
            'lines' => Text::diff($old, $new),
        ]);
    }

    /** POST page.save —— 新建 / 更新 */
    public static function save(): never
    {
        Auth::verifyCsrf();
        $b = Response::body();
        $tag = Text::normalizeTag((string)($b['tag'] ?? ''));
        $title = trim((string)($b['title'] ?? ''));
        $body = (string)($b['body'] ?? '');
        $style = (string)($b['style'] ?? '');
        $comment = trim((string)($b['comment'] ?? ''));
        if ($tag === '' || $title === '') {
            Response::error('页面名和标题不能为空。', 422, 'INVALID_INPUT');
        }
        if (mb_strlen($body, 'UTF-8') > 2 * 1024 * 1024) {
            Response::error('正文过长。', 422, 'INVALID_INPUT');
        }
        if (mb_strlen($style, 'UTF-8') > 2 * 1024 * 1024) {
            Response::error('样式表过长。', 422, 'INVALID_INPUT');
        }

        $user = Auth::user(); // 可为 null（匿名，等级允许时）
        if (Auth::isDisabled($user)) {
            Response::error('该账号已被冻结/封禁，无法编辑页面。', 403, 'ACCOUNT_DISABLED');
        }
        $db = Database::pdo();
        $page = self::find($tag);

        $aclEdit = $page !== null ? (int)($page['acl_edit'] ?? 3) : (int)Settings::defaultLevel('edit');
        if (!Auth::canLevel($aclEdit, $user)) {
            Response::error('你没有编辑该页面的权限。', 403, 'FORBIDDEN');
        }

        $now = Database::now();
        $userId = $user !== null ? (int)$user['id'] : null;

        // 新建页面：插入页面记录并写入首个修订（revision=1）
        if ($page === null) {
            $st = $db->prepare(
                'INSERT INTO ' . Database::qi('pages')
                . ' (' . Database::qi('tag') . ', ' . Database::qi('title') . ', ' . Database::qi('body') . ', '
                . Database::qi('style') . ', ' . Database::qi('comment') . ', ' . Database::qi('user_id') . ', '
                . Database::qi('created_by') . ', ' . Database::qi('created_at') . ', ' . Database::qi('updated_at') . ', '
                . Database::qi('revision') . ', ' . Database::qi('acl_read') . ', ' . Database::qi('acl_edit') . ', '
                . Database::qi('acl_history') . ', ' . Database::qi('acl_diff') . ', ' . Database::qi('acl_backlinks') . ', '
                . Database::qi('acl_acl') . ', ' . Database::qi('acl_contributors') . ') '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $tag, $title, $body, $style, $comment, $userId, $userId,
                $now, $now,
                Settings::defaultLevel('read'), Settings::defaultLevel('edit'), Settings::defaultLevel('history'),
                Settings::defaultLevel('diff'), Settings::defaultLevel('backlinks'), Settings::defaultLevel('perms'),
                Settings::defaultLevel('contributors'),
            ]);
            $pageId = Database::lastInsertId();
            self::insertRevision($pageId, $tag, $title, $body, $style, $comment, $userId, 1, $now);
            Response::data(['created' => true, 'tag' => $tag, 'revision' => 1], 201);
        }

        // 更新现有页面：递增修订号并写入新修订
        $newRevision = (int)$page['revision'] + 1;
        $st = $db->prepare(
            'UPDATE ' . Database::qi('pages') . ' SET ' . Database::qi('title') . ' = ?, ' . Database::qi('body') . ' = ?, '
            . Database::qi('style') . ' = ?, ' . Database::qi('comment') . ' = ?, ' . Database::qi('user_id') . ' = ?, '
            . Database::qi('updated_at') . ' = ?, ' . Database::qi('revision') . ' = ? WHERE id = ?'
        );
        $st->execute([$title, $body, $style, $comment, $userId, $now, $newRevision, (int)$page['id']]);
        self::insertRevision((int)$page['id'], $tag, $title, $body, $style, $comment, $userId, $newRevision, $now);

        Response::data(['created' => false, 'tag' => $tag, 'revision' => $newRevision]);
    }

    /** POST page.update-acl */
    public static function updateAcl(): never
    {
        Auth::verifyCsrf();
        $b = Response::body();
        $tag = Text::normalizeTag((string)($b['tag'] ?? ''));
        $page = self::find($tag);
        if ($page === null) {
            Response::error('页面不存在。', 404, 'NOT_FOUND');
        }
        $user = Auth::requireActive();
        // 满足该页面「权限管理」等级要求的用户可修改访问控制
        if (!Auth::canLevel((int)($page['acl_acl'] ?? 1), $user)) {
            Response::error('没有权限修改该页面的访问控制。', 403, 'FORBIDDEN');
        }
        $fields = [
            'acl_read'         => 'read',
            'acl_edit'         => 'edit',
            'acl_history'      => 'history',
            'acl_diff'         => 'diff',
            'acl_backlinks'    => 'backlinks',
            'acl_acl'          => 'perms',
            'acl_contributors' => 'contributors',
        ];
        $values = [];
        $set = [];
        foreach ($fields as $col => $kind) {
            $v = trim((string)($b[$col] ?? $page[$col] ?? Settings::defaultLevel($kind)));
            if (!in_array($v, ['0', '1', '2', '3'], true)) {
                Response::error('权限等级只能为 0~3。', 422, 'INVALID_INPUT');
            }
            $values[] = $v;
            $set[] = Database::qi($col) . ' = ?';
        }
        $values[] = (int)$page['id'];
        $st = Database::pdo()->prepare(
            'UPDATE ' . Database::qi('pages') . ' SET ' . implode(', ', $set) . ' WHERE id = ?'
        );
        $st->execute($values);
        Response::data(['ok' => true]);
    }

    /** POST page.delete —— 仅管理员 */
    public static function delete(): never
    {
        Auth::requireAdmin();
        Auth::verifyCsrf();
        $b = Response::body();
        $tag = Text::normalizeTag((string)($b['tag'] ?? ''));
        $page = self::find($tag);
        if ($page === null) {
            Response::error('页面不存在。', 404, 'NOT_FOUND');
        }
        $db = Database::pdo();
        $id = (int)$page['id'];
        foreach (['revisions', 'watchers'] as $t) {
            $st = $db->prepare('DELETE FROM ' . Database::qi($t) . ' WHERE ' . Database::qi('page_id') . ' = ?');
            $st->execute([$id]);
        }
        $st = $db->prepare('DELETE FROM ' . Database::qi('pages') . ' WHERE id = ?');
        $st->execute([$id]);
        Response::data(['ok' => true]);
    }

    /** POST page.revert —— 回滚到指定版本（仅管理员），生成一条新修订 */
    public static function revert(): never
    {
        $admin = Auth::requireAdmin();
        Auth::verifyCsrf();
        $b = Response::body();
        $tag = Text::normalizeTag((string)($b['tag'] ?? ''));
        $rev = (int)($b['revision'] ?? 0);
        $page = self::find($tag);
        if ($page === null) {
            Response::error('页面不存在。', 404, 'NOT_FOUND');
        }
        $revs = self::revisionBodies((int)$page['id']);
        if (!isset($revs[$rev])) {
            Response::error('目标版本不存在。', 404, 'NOT_FOUND');
        }
        if ($rev === (int)$page['revision']) {
            Response::error('当前已是最新版本，无需回滚。', 422, 'INVALID_INPUT');
        }

        $target = $revs[$rev];
        $now = Database::now();
        $newRevision = (int)$page['revision'] + 1;
        $comment = '回滚到 r' . $rev . ($target['comment'] !== '' ? '（' . $target['comment'] . '）' : '');

        $st = Database::pdo()->prepare(
            'UPDATE ' . Database::qi('pages') . ' SET ' . Database::qi('title') . ' = ?, ' . Database::qi('body') . ' = ?, '
            . Database::qi('style') . ' = ?, ' . Database::qi('comment') . ' = ?, ' . Database::qi('user_id') . ' = ?, '
            . Database::qi('updated_at') . ' = ?, ' . Database::qi('revision') . ' = ? WHERE id = ?'
        );
        $st->execute([$target['title'], $target['body'], $target['style'], $comment, (int)$admin['id'], $now, $newRevision, (int)$page['id']]);
        self::insertRevision((int)$page['id'], $tag, $target['title'], $target['body'], $target['style'], $comment, (int)$admin['id'], $newRevision, $now);

        Response::data(['revision' => $newRevision]);
    }

    /** POST page.delete-revision —— 仅管理员，删除指定修订（当前最新修订不可删） */
    public static function deleteRevision(): never
    {
        $admin = Auth::requireAdmin();
        Auth::verifyCsrf();
        $b = Response::body();
        $tag = Text::normalizeTag((string)($b['tag'] ?? ''));
        $rev = (int)($b['revision'] ?? 0);
        $page = self::find($tag);
        if ($page === null) {
            Response::error('页面不存在。', 404, 'NOT_FOUND');
        }
        if ($rev <= 0) {
            Response::error('无效的修订号。', 422, 'INVALID_INPUT');
        }
        if ($rev === (int)$page['revision']) {
            Response::error('不能删除当前最新修订。', 422, 'INVALID_INPUT');
        }
        $revs = self::revisionBodies((int)$page['id']);
        if (!isset($revs[$rev])) {
            Response::error('目标修订不存在。', 404, 'NOT_FOUND');
        }
        if (count($revs) <= 1) {
            Response::error('至少需保留一条修订。', 422, 'INVALID_INPUT');
        }
        $st = Database::pdo()->prepare(
            'DELETE FROM ' . Database::qi('revisions')
            . ' WHERE ' . Database::qi('page_id') . ' = ? AND ' . Database::qi('revision') . ' = ?'
        );
        $st->execute([(int)$page['id'], $rev]);
        if ($st->rowCount() === 0) {
            Response::error('目标修订不存在。', 404, 'NOT_FOUND');
        }
        Response::data(['ok' => true]);
    }

    /** GET page.backlinks?tag= */
    public static function backlinks(): never
    {
        $tag = Text::normalizeTag((string)($_GET['tag'] ?? ''));
        $page = self::find($tag);
        if ($page === null) {
            Response::error('页面不存在。', 404, 'NOT_FOUND');
        }
        if (!Auth::canLevel((int)($page['acl_backlinks'] ?? 3), Auth::user())) {
            Response::error('你没有查看该页面回链的权限。', 403, 'FORBIDDEN');
        }
        $st = Database::pdo()->prepare(
            'SELECT ' . Database::qi('tag') . ', ' . Database::qi('title') . ' FROM ' . Database::qi('pages')
            . ' WHERE ' . Database::qi('body') . ' LIKE ? ORDER BY ' . Database::qi('tag')
        );
        $st->execute(['%[[' . $tag . ']]%']);
        Response::data(array_map(fn($r) => [
            'tag'   => (string)$r['tag'],
            'title' => (string)$r['title'],
        ], $st->fetchAll()));
    }

    /** GET page.contributors?tag= —— 页面贡献者列表（含编辑次数） */
    public static function contributorsEndpoint(): never
    {
        $tag = Text::normalizeTag((string)($_GET['tag'] ?? ''));
        $page = self::find($tag);
        if ($page === null) {
            Response::data([]);
        }
        if (!Auth::canLevel((int)($page['acl_contributors'] ?? 0), Auth::user())) {
            Response::error('你没有查看该页面贡献者的权限。', 403, 'FORBIDDEN');
        }
        Response::data(self::contributors((int)$page['id']));
    }

    /** GET page.similar?tag= —— 基于标题/正文关键词相似页面 */
    public static function similar(): never
    {
        $tag = Text::normalizeTag((string)($_GET['tag'] ?? ''));
        $page = self::find($tag);
        if ($page === null) {
            Response::data([]);
        }
        $words = self::keywords((string)$page['title'] . ' ' . (string)$page['body']);
        if (!$words) {
            Response::data([]);
        }
        $where = [];
        $params = [];
        foreach ($words as $w) {
            $where[] = Database::qi('body') . ' LIKE ?';
            $params[] = '%' . $w . '%';
        }
        $st = Database::pdo()->prepare(
            'SELECT ' . Database::qi('tag') . ', ' . Database::qi('title') . ' FROM ' . Database::qi('pages')
            . ' WHERE ' . Database::qi('tag') . ' <> ? AND (' . implode(' OR ', $where) . ') LIMIT 10'
        );
        $st->execute(array_merge([$tag], $params));
        Response::data(array_map(fn($r) => [
            'tag'   => (string)$r['tag'],
            'title' => (string)$r['title'],
        ], $st->fetchAll()));
    }

    /** GET page.perms?tag= */
    public static function perms(): never
    {
        $tag = Text::normalizeTag((string)($_GET['tag'] ?? ''));
        $page = self::find($tag);
        if ($page === null) {
            Response::data([
                'acl_read'         => Settings::defaultLevel('read'),
                'acl_edit'         => Settings::defaultLevel('edit'),
                'acl_history'      => Settings::defaultLevel('history'),
                'acl_diff'         => Settings::defaultLevel('diff'),
                'acl_backlinks'    => Settings::defaultLevel('backlinks'),
                'acl_acl'          => Settings::defaultLevel('perms'),
                'acl_contributors' => Settings::defaultLevel('contributors'),
            ]);
        }
        Response::data([
            'acl_read'         => (string)$page['acl_read'],
            'acl_edit'         => (string)$page['acl_edit'],
            'acl_history'      => (string)$page['acl_history'],
            'acl_diff'         => (string)$page['acl_diff'],
            'acl_backlinks'    => (string)$page['acl_backlinks'],
            'acl_acl'          => (string)$page['acl_acl'],
            'acl_contributors' => (string)$page['acl_contributors'],
        ]);
    }

    // ==================== 内部工具 ====================

    /** 按 tag 查页面 */
    private static function find(string $tag): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM ' . Database::qi('pages') . ' WHERE ' . Database::qi('tag') . ' = ?');
        $st->execute([$tag]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** 对外页面负载 */
    private static function payload(array $p): array
    {
        $creator = null;
        $creatorNickname = null;
        if (!empty($p['created_by'])) {
            $st = Database::pdo()->prepare('SELECT ' . Database::qi('username') . ', ' . Database::qi('nickname') . ' FROM ' . Database::qi('users') . ' WHERE id = ?');
            $st->execute([(int)$p['created_by']]);
            $row = $st->fetch();
            if ($row) {
                $creator = (string)$row['username'];
                $creatorNickname = (string)$row['nickname'] !== '' ? (string)$row['nickname'] : null;
            }
        }
        $lastEditor = null;
        $lastNickname = null;
        if (!empty($p['user_id'])) {
            $st = Database::pdo()->prepare('SELECT ' . Database::qi('username') . ', ' . Database::qi('nickname') . ' FROM ' . Database::qi('users') . ' WHERE id = ?');
            $st->execute([(int)$p['user_id']]);
            $row = $st->fetch();
            if ($row) {
                $lastEditor = (string)$row['username'];
                $lastNickname = (string)$row['nickname'] !== '' ? (string)$row['nickname'] : null;
            }
        }
        return [
            'id'         => (int)$p['id'],
            'tag'        => (string)$p['tag'],
            'title'      => (string)$p['title'],
            'body'       => (string)$p['body'],
            'style'      => (string)($p['style'] ?? ''),
            'comment'    => (string)$p['comment'],
            'revision'   => (int)$p['revision'],
            'hits'       => (int)$p['hits'],
            'created_at' => (string)$p['created_at'],
            'updated_at' => (string)$p['updated_at'],
            'created_by'      => $creator,
            'creator_nickname'=> $creatorNickname,
            'last_editor'     => $lastEditor,
            'last_nickname'   => $lastNickname,
            'acl'        => [
                'read'         => (string)$p['acl_read'],
                'edit'         => (string)$p['acl_edit'],
                'history'      => (string)$p['acl_history'],
                'diff'         => (string)$p['acl_diff'],
                'backlinks'    => (string)$p['acl_backlinks'],
                'acl'          => (string)$p['acl_acl'],
                'contributors' => (string)$p['acl_contributors'],
            ],
        ];
    }

    private static function insertRevision(int $pageId, string $tag, string $title, string $body, string $style, string $comment, ?int $userId, int $revision, string $now): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO ' . Database::qi('revisions')
            . ' (' . Database::qi('page_id') . ', ' . Database::qi('tag') . ', ' . Database::qi('title') . ', '
            . Database::qi('body') . ', ' . Database::qi('style') . ', ' . Database::qi('comment') . ', '
            . Database::qi('user_id') . ', ' . Database::qi('revision') . ', ' . Database::qi('created_at') . ') '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$pageId, $tag, $title, $body, $style, $comment, $userId, $revision, $now]);
    }

    /** 版本号 => [body, meta]，升序 */
    private static function revisionBodies(int $pageId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT r.' . Database::qi('revision') . ', r.' . Database::qi('title') . ', r.' . Database::qi('comment') . ', '
            . 'r.' . Database::qi('body') . ', r.' . Database::qi('style') . ', r.' . Database::qi('created_at') . ', r.' . Database::qi('user_id') . ', '
            . 'u.' . Database::qi('username') . ', u.' . Database::qi('nickname')
            . ' FROM ' . Database::qi('revisions') . ' r'
            . ' LEFT JOIN ' . Database::qi('users') . ' u ON u.' . Database::qi('id') . ' = r.' . Database::qi('user_id')
            . ' WHERE r.' . Database::qi('page_id') . ' = ? ORDER BY r.' . Database::qi('revision')
        );
        $st->execute([$pageId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['revision']] = [
                'revision'   => (int)$r['revision'],
                'title'      => (string)$r['title'],
                'comment'    => (string)$r['comment'],
                'body'       => (string)$r['body'],
                'style'      => (string)($r['style'] ?? ''),
                'created_at' => (string)$r['created_at'],
                'user_id'    => $r['user_id'] !== null ? (int)$r['user_id'] : null,
                'username'   => $r['username'] !== null ? (string)$r['username'] : null,
                'nickname'   => $r['nickname'] !== null ? (string)$r['nickname'] : null,
            ];
        }
        return $out;
    }

    private static function isWatching(int $pageId, int $userId): bool
    {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM ' . Database::qi('watchers') . ' WHERE ' . Database::qi('page_id') . ' = ? AND ' . Database::qi('user_id') . ' = ?');
        $st->execute([$pageId, $userId]);
        return (int)$st->fetchColumn() > 0;
    }

    /** 摘要：命中关键词附近的文本 */
    private static function snippet(string $body, string $q, int $len = 160): string
    {
        $plain = Text::plainText($body, 1000);
        $pos = mb_stripos($plain, $q, 0, 'UTF-8');
        if ($pos === false) {
            return mb_substr($plain, 0, $len, 'UTF-8') . (mb_strlen($plain, 'UTF-8') > $len ? '…' : '');
        }
        $start = max(0, $pos - (int)($len / 3));
        $cut = mb_substr($plain, $start, $len, 'UTF-8');
        return ($start > 0 ? '…' : '') . $cut . '…';
    }

    /** 提取正文关键词（用于相似页） */
    private static function keywords(string $text, int $max = 8): array
    {
        $t = Text::plainText($text, 2000);
        $words = preg_split('/[\s\p{P}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn($w) => mb_strlen($w, 'UTF-8') >= 2 && mb_strlen($w, 'UTF-8') <= 16));
        $count = array_count_values($words);
        arsort($count);
        return array_slice(array_keys($count), 0, $max);
    }

    /** 页面贡献者（去重，含编辑次数、昵称、头像；按最近编辑时间排序） */
    private static function contributors(int $pageId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT u.' . Database::qi('username') . ', u.' . Database::qi('nickname') . ', u.' . Database::qi('avatar')
            . ', COUNT(*) AS ' . Database::qi('edits') . ', MAX(r.' . Database::qi('created_at') . ') AS ' . Database::qi('last_at')
            . ' FROM ' . Database::qi('revisions') . ' r'
            . ' JOIN ' . Database::qi('users') . ' u ON u.' . Database::qi('id') . ' = r.' . Database::qi('user_id')
            . ' WHERE r.' . Database::qi('page_id') . ' = ? AND r.' . Database::qi('user_id') . ' IS NOT NULL'
            . ' GROUP BY u.' . Database::qi('id') . ', u.' . Database::qi('username') . ', u.' . Database::qi('nickname') . ', u.' . Database::qi('avatar')
            . ' ORDER BY ' . Database::qi('last_at') . ' DESC'
        );
        $st->execute([$pageId]);
        return array_map(fn($r) => [
            'username' => (string)$r['username'],
            'nickname' => (string)($r['nickname'] ?? ''),
            'avatar'   => (string)($r['avatar'] ?? ''),
            'edits'    => (int)$r['edits'],
        ], $st->fetchAll());
    }

    /** 订阅者数量 */
    private static function subscriberCount(int $pageId): int
    {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM ' . Database::qi('watchers') . ' WHERE ' . Database::qi('page_id') . ' = ?');
        $st->execute([$pageId]);
        return (int)$st->fetchColumn();
    }
}
