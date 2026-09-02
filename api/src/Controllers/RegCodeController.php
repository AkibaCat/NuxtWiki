<?php
declare(strict_types=1);

/** 注册码管理（仅管理员） */
final class RegCodeController
{
    /** POST regcode.generate —— 批量生成注册码 */
    public static function generate(): never
    {
        Auth::requireAdmin();
        Auth::verifyCsrf();
        $b = Response::body();
        $count = min(100, max(1, (int)($b['count'] ?? 1)));
        $db = Database::pdo();
        $now = Database::now();
        $st = $db->prepare(
            'INSERT INTO ' . Database::qi('regcodes') . ' (' . Database::qi('code') . ', ' . Database::qi('created_at') . ') VALUES (?, ?)'
        );
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = 'ZC-' . bin2hex(random_bytes(8));
            try {
                $st->execute([$code, $now]);
                $codes[] = $code;
            } catch (Throwable) {
                // 唯一键冲突重试一次
                $code = 'ZC-' . bin2hex(random_bytes(8));
                $st->execute([$code, $now]);
                $codes[] = $code;
            }
        }
        Response::data(['codes' => $codes], 201);
    }

    /** GET regcode.list —— 注册码列表 */
    public static function list(): never
    {
        Auth::requireAdmin();
        $rows = Database::pdo()->query(
            'SELECT r.' . Database::qi('id') . ', r.' . Database::qi('code') . ', r.' . Database::qi('created_at') . ', '
            . 'r.' . Database::qi('used_at') . ', u.' . Database::qi('username')
            . ' FROM ' . Database::qi('regcodes') . ' r'
            . ' LEFT JOIN ' . Database::qi('users') . ' u ON u.' . Database::qi('id') . ' = r.' . Database::qi('user_id')
            . ' ORDER BY r.' . Database::qi('id') . ' DESC'
        )->fetchAll();
        Response::data(array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'code'       => (string)$r['code'],
            'created_at' => (string)$r['created_at'],
            'used_at'    => $r['used_at'] !== null ? (string)$r['used_at'] : null,
            'username'   => $r['username'] !== null ? (string)$r['username'] : null,
        ], $rows));
    }

    /** POST regcode.delete —— 删除已使用的注册码 */
    public static function delete(): never
    {
        Auth::requireAdmin();
        Auth::verifyCsrf();
        $b = Response::body();
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) {
            Response::error('缺少 ID。', 422, 'INVALID_INPUT');
        }
        $st = Database::pdo()->prepare('SELECT ' . Database::qi('user_id') . ' FROM ' . Database::qi('regcodes') . ' WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            Response::error('注册码不存在。', 404, 'NOT_FOUND');
        }
        if ($row['user_id'] === null) {
            Response::error('未使用的注册码请使用「销毁」操作。', 422, 'INVALID_INPUT');
        }
        $st = Database::pdo()->prepare('DELETE FROM ' . Database::qi('regcodes') . ' WHERE id = ?');
        $st->execute([$id]);
        Response::data(['ok' => true]);
    }

    /** POST regcode.destroy —— 销毁未使用的注册码 */
    public static function destroy(): never
    {
        Auth::requireAdmin();
        Auth::verifyCsrf();
        $b = Response::body();
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) {
            Response::error('缺少 ID。', 422, 'INVALID_INPUT');
        }
        $st = Database::pdo()->prepare('SELECT ' . Database::qi('user_id') . ' FROM ' . Database::qi('regcodes') . ' WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            Response::error('注册码不存在。', 404, 'NOT_FOUND');
        }
        if ($row['user_id'] !== null) {
            Response::error('已使用的注册码请使用「删除」操作。', 422, 'INVALID_INPUT');
        }
        $st = Database::pdo()->prepare('DELETE FROM ' . Database::qi('regcodes') . ' WHERE id = ?');
        $st->execute([$id]);
        Response::data(['ok' => true]);
    }
}