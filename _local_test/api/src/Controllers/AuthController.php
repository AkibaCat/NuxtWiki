<?php
declare(strict_types=1);

/** 认证：登录 / 注册 / 登出 / 当前用户 / CSRF */
final class AuthController
{
    /** GET auth.me —— 当前用户 + CSRF（前端初始化用） */
    public static function me(): never
    {
        Response::data([
            'user' => Auth::publicUser(Auth::user()),
            'csrf' => Auth::csrf(),
            'registration_open' => Settings::allowRegistration(),
            'site' => [
                'name'        => Settings::siteName(),
                'description' => Settings::siteDescription(),
                'home_tag'    => Settings::homeTag(),
            ],
        ]);
    }

    /** GET auth.csrf */
    public static function csrf(): never
    {
        Response::data(['csrf' => Auth::csrf()]);
    }

    /** POST auth.login */
    public static function login(): never
    {
        $b = Response::body();
        $username = trim((string)($b['username'] ?? ''));
        $password = (string)($b['password'] ?? '');
        if ($username === '' || $password === '') {
            Response::error('请输入用户名和密码。', 422, 'INVALID_INPUT');
        }

        $st = Database::pdo()->prepare('SELECT * FROM ' . Database::qi('users') . ' WHERE username = ?');
        $st->execute([$username]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, $u['password'])) {
            Response::error('用户名或密码错误。', 401, 'LOGIN_FAILED');
        }

        // 封禁账号禁止登录（冻结账号仅可登录与订阅）
        if ((string)($u['status'] ?? 'active') === 'banned') {
            Response::error('该账号已被封禁，无法登录。', 403, 'ACCOUNT_BANNED');
        }

        Auth::login((int)$u['id']);
        Response::data(['user' => Auth::publicUser($u), 'csrf' => Auth::csrf()]);
    }

    /** POST auth.register */
    public static function register(): never
    {
        Auth::verifyCsrf();
        if (!Settings::allowRegistration()) {
            Response::error('本站未开放注册。', 403, 'REGISTRATION_CLOSED');
        }
        $b = Response::body();
        $username = trim((string)($b['username'] ?? ''));
        $password = (string)($b['password'] ?? '');
        $confirm  = (string)($b['confirm'] ?? '');
        $regcode  = trim((string)($b['regcode'] ?? ''));

        if (mb_strlen($username, 'UTF-8') < 2 || mb_strlen($username, 'UTF-8') > 32) {
            Response::error('用户名长度需在 2~32 个字符之间。', 422, 'INVALID_INPUT');
        }
        if (preg_match('/[^\p{L}\p{N}_\-\.@]/u', $username)) {
            Response::error('用户名包含非法字符。', 422, 'INVALID_INPUT');
        }
        if (strlen($password) < 6) {
            Response::error('密码至少需要 6 位。', 422, 'INVALID_INPUT');
        }
        if ($confirm !== '' && $confirm !== $password) {
            Response::error('两次输入的密码不一致。', 422, 'INVALID_INPUT');
        }

        // 注册码校验：格式 ZC-[16位小写字母+数字]
        if (!preg_match('/^ZC-[a-z0-9]{16}$/i', $regcode)) {
            Response::error('注册码格式不正确。', 422, 'INVALID_INPUT');
        }

        $db = Database::pdo();
        $st = $db->prepare('SELECT COUNT(*) FROM ' . Database::qi('users') . ' WHERE username = ?');
        $st->execute([$username]);
        if ((int)$st->fetchColumn() > 0) {
            Response::error('该用户名已被占用。', 409, 'USERNAME_TAKEN');
        }

        // 占用注册码（原子性：在事务中先标记后创建用户）
        $db->beginTransaction();
        try {
            $st = $db->prepare(
                'SELECT ' . Database::qi('id') . ' FROM ' . Database::qi('regcodes') . ' WHERE ' . Database::qi('code') . ' = ? AND ' . Database::qi('user_id') . ' IS NULL'
            );
            $st->execute([strtoupper($regcode)]);
            $codeId = $st->fetchColumn();
            if ($codeId === false) {
                $db->rollBack();
                Response::error('注册码无效或已被使用。', 422, 'INVALID_REGCODE');
            }

            $now = Database::now();
            $st = $db->prepare(
                'INSERT INTO ' . Database::qi('users')
                . ' (' . Database::qi('username') . ', ' . Database::qi('email') . ', ' . Database::qi('password') . ', '
                . Database::qi('is_admin') . ', ' . Database::qi('created_at') . ', ' . Database::qi('updated_at') . ') '
                . 'VALUES (?, \'\', ?, 0, ?, ?)'
            );
            $st->execute([$username, password_hash($password, PASSWORD_DEFAULT), $now, $now]);

            $id = Database::lastInsertId();
            $st = $db->prepare(
                'UPDATE ' . Database::qi('regcodes') . ' SET ' . Database::qi('user_id') . ' = ?, ' . Database::qi('used_at') . ' = ? WHERE id = ?'
            );
            $st->execute([$id, $now, (int)$codeId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('注册失败，请重试。', 500, 'REGISTER_FAILED');
        }

        Auth::login($id);
        Response::data(['user' => Auth::publicUser(Auth::user()), 'csrf' => Auth::csrf()], 201);
    }

    /** POST auth.logout */
    public static function logout(): never
    {
        Auth::verifyCsrf();
        Auth::logout();
        Response::data(['ok' => true]);
    }
}
