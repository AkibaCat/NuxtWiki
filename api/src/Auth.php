<?php
declare(strict_types=1);

/** 认证与会话（PHP Session + Cookie），同源部署 */
final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $cfg = app_config()['security'] ?? [];
        session_name($cfg['session_name'] ?? 'NUXTWIKI');
        session_set_cookie_params([
            'lifetime' => (int)($cfg['session_lifetime'] ?? 7 * 24 * 3600),
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => false,
        ]);
        session_start();
    }

    /** 当前登录用户（未登录返回 null） */
    public static function user(): ?array
    {
        self::startSession();
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM ' . Database::qi('users') . ' WHERE id = ?');
        $st->execute([(int)$id]);
        $u = $st->fetch();
        if (!$u) {
            unset($_SESSION['user_id']);
            return null;
        }
        return $u;
    }

    public static function isAdmin(): bool
    {
        return self::levelOf(self::user()) === 1;
    }

    /**
     * 当前用户等级（0 访客·未登录 / 1 管理员 / 2 高级用户 / 3 普通用户）。
     * 未登录返回 0；旧数据无 level 时按 is_admin 兜底。
     */
    public static function levelOf(?array $u): int
    {
        if ($u === null) {
            return 0;
        }
        $level = (int)($u['level'] ?? 0);
        if ($level === 1 || $level === 2 || $level === 3) {
            return $level;
        }
        return (int)$u['is_admin'] === 1 ? 1 : 3;
    }

    /**
     * 等级 → 权限优先级（高者拥有更多权限）：
     * 1 管理员=3 > 2 高级用户=2 > 3 普通用户=1 > 0 访客=0
     */
    public static function levelPriority(int $level): int
    {
        return match ($level) {
            1       => 3,
            2       => 2,
            3       => 1,
            default => 0,
        };
    }

    /**
     * 页面操作等级校验：用户优先级 >= 所需优先级。
     * $required 为页面存储的等级值（0~3，非法值按 3 处理）。
     * 冻结/封禁等禁用状态不影响等级判断，禁用操作由 requireActive/isDisabled 另行拦截。
     */
    public static function canLevel(int $required, ?array $user): bool
    {
        if (!in_array($required, [0, 1, 2, 3], true)) {
            $required = 3;
        }
        return self::levelPriority(self::levelOf($user)) >= self::levelPriority($required);
    }

    public static function requireLogin(): array
    {
        $u = self::user();
        if ($u === null) {
            Response::error('请先登录。', 401, 'UNAUTHORIZED');
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::requireLogin();
        if (self::levelOf($u) !== 1) {
            Response::error('需要管理员权限。', 403, 'FORBIDDEN');
        }
        return $u;
    }

    /**
     * 要求账号处于可用状态（非冻结 / 非封禁）。
     * 冻结：仅可登录与订阅；封禁：禁止一切操作（登录也拒绝）。
     */
    public static function requireActive(): array
    {
        $u = self::requireLogin();
        $status = (string)($u['status'] ?? 'active');
        if ($status !== 'active') {
            Response::error(
                $status === 'banned' ? '该账号已被封禁。' : '该账号已被冻结，仅可登录与订阅。',
                403,
                'ACCOUNT_DISABLED'
            );
        }
        return $u;
    }

    /** 当前用户是否处于冻结/封禁状态 */
    public static function isDisabled(?array $u): bool
    {
        return $u !== null && (string)($u['status'] ?? 'active') !== 'active';
    }

    public static function login(int $userId): void
    {
        self::startSession();
        // 重新生成会话 ID，防止会话固定（session fixation）攻击
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** CSRF Token（会话内生成） */
    public static function csrf(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
        if (!is_string($token) || !hash_equals(self::csrf(), $token)) {
            Response::error('安全校验失败（CSRF），请刷新页面重试。', 419, 'CSRF_FAILED');
        }
    }

    /**
     * 判断 ACL 权限。
     * 语法（与 WackoWiki 类似）：
     *   *       所有用户
     *   ?       已登录用户
     *   $       管理员
     *   用户名   逗号分隔的用户名列表（不区分大小写）
     */
    public static function checkAcl(string $acl, ?array $user): bool
    {
        $acl = trim($acl);
        if ($acl === '' || $acl === '*') {
            return true;
        }
        foreach (explode(',', $acl) as $part) {
            $p = strtolower(trim($part));
            if ($p === '*' || $p === '') {
                return true;
            }
            if ($p === '?' && $user !== null) {
                return true;
            }
            if ($p === '$' && $user !== null && self::levelOf($user) === 1) {
                return true;
            }
            if ($user !== null && strtolower((string)$user['username']) === $p) {
                return true;
            }
        }
        return false;
    }

    /** 用户信息对外脱敏 */
    public static function publicUser(?array $u): ?array
    {
        if ($u === null) {
            return null;
        }
        return [
            'id'         => (int)$u['id'],
            'username'   => (string)$u['username'],
            'nickname'   => (string)($u['nickname'] ?? ''),
            'avatar'     => (string)($u['avatar'] ?? ''),
            'bio'        => (string)($u['bio'] ?? ''),
            'socials'    => self::decodeSocials((string)($u['socials'] ?? '')),
            'level'      => self::levelOf($u),
            'is_admin'   => self::levelOf($u) === 1,
            'status'     => (string)($u['status'] ?? 'active'),
            'reason'     => (string)($u['reason'] ?? ''),
            'created_at' => (string)$u['created_at'],
        ];
    }

    /** socials 字段为 JSON 字符串，解码为对象 */
    public static function decodeSocials(string $json): array
    {
        $v = json_decode($json, true);
        return is_array($v) ? $v : [];
    }
}
