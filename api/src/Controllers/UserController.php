<?php
declare(strict_types=1);

/** 用户管理（仅管理员） */
final class UserController
{
    /** GET users.list —— 用户列表（支持 ?q= 按用户名/昵称搜索，不含 ID） */
    public static function list(): never
    {
        Auth::requireAdmin();
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = 'SELECT ' . Database::qi('username') . ', ' . Database::qi('nickname') . ', ' . Database::qi('email') . ', '
            . Database::qi('is_admin') . ', ' . Database::qi('level') . ', ' . Database::qi('status') . ', ' . Database::qi('reason') . ', ' . Database::qi('created_at') . ', ' . Database::qi('updated_at')
            . ' FROM ' . Database::qi('users');
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE ' . Database::qi('username') . ' LIKE ? OR ' . Database::qi('nickname') . ' LIKE ?';
            $params = ['%' . $q . '%', '%' . $q . '%'];
        }
        $sql .= ' ORDER BY ' . Database::qi('id') . ' DESC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['is_admin'] = (int)$r['is_admin'] === 1;
            $r['level'] = Auth::levelOf($r);
        }
        Response::data($rows);
    }

    /** POST users.create */
    public static function create(): never
    {
        Auth::requireAdmin();
        Auth::verifyCsrf();
        $b = Response::body();
        $username = trim((string)($b['username'] ?? ''));
        $password = (string)($b['password'] ?? '');
        // 等级：1 管理员 / 2 高级 / 3 普通（默认普通）
        $level = isset($b['level']) ? (int)$b['level'] : 3;
        if (!in_array($level, [1, 2, 3], true)) {
            Response::error('无效的用户等级。', 422, 'INVALID_INPUT');
        }

        if (mb_strlen($username, 'UTF-8') < 2 || mb_strlen($username, 'UTF-8') > 32) {
            Response::error('用户名长度需在 2~32 个字符之间。', 422, 'INVALID_INPUT');
        }
        if (strlen($password) < 6) {
            Response::error('密码至少需要 6 位。', 422, 'INVALID_INPUT');
        }
        $db = Database::pdo();
        $st = $db->prepare('SELECT COUNT(*) FROM ' . Database::qi('users') . ' WHERE username = ?');
        $st->execute([$username]);
        if ((int)$st->fetchColumn() > 0) {
            Response::error('该用户名已被占用。', 409, 'USERNAME_TAKEN');
        }
        $now = Database::now();
        // 邮箱仅作展示（订阅通知已移除），后台创建用户不再填写邮箱
        $st = $db->prepare(
            'INSERT INTO ' . Database::qi('users')
            . ' (' . Database::qi('username') . ', ' . Database::qi('email') . ', ' . Database::qi('password') . ', '
            . Database::qi('is_admin') . ', ' . Database::qi('level') . ', ' . Database::qi('status') . ', ' . Database::qi('created_at') . ', ' . Database::qi('updated_at') . ') '
            . 'VALUES (?, \'\', ?, ?, ?, \'active\', ?, ?)'
        );
        $st->execute([$username, password_hash($password, PASSWORD_DEFAULT), $level === 1 ? 1 : 0, $level, $now, $now]);
        Response::data(['id' => Database::lastInsertId()], 201);
    }

    /** POST users.update —— 按用户名更新（邮箱/等级/密码） */
    public static function update(): never
    {
        Auth::requireAdmin();
        Auth::verifyCsrf();
        $b = Response::body();
        $username = trim((string)($b['username'] ?? ''));
        if ($username === '') {
            Response::error('缺少用户名。', 422, 'INVALID_INPUT');
        }
        $db = Database::pdo();
        $st = $db->prepare('SELECT * FROM ' . Database::qi('users') . ' WHERE ' . Database::qi('username') . ' = ?');
        $st->execute([$username]);
        $u = $st->fetch();
        if (!$u) {
            Response::error('用户不存在。', 404, 'NOT_FOUND');
        }
        $me = Auth::requireAdmin();
        $email = trim((string)($b['email'] ?? $u['email']));
        $level = isset($b['level']) ? (int)$b['level'] : Auth::levelOf($u);
        if (!in_array($level, [1, 2, 3], true)) {
            Response::error('无效的用户等级。', 422, 'INVALID_INPUT');
        }
        if ((int)$u['id'] === (int)$me['id'] && $level !== 1) {
            Response::error('不能取消自己的管理员权限。', 422, 'INVALID_INPUT');
        }
        // 选择某个权限等级即视为恢复正常账号
        $status = 'active';
        $reason = '';
        $password = (string)($b['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < 6) {
                Response::error('密码至少需要 6 位。', 422, 'INVALID_INPUT');
            }
            $st = $db->prepare(
                'UPDATE ' . Database::qi('users') . ' SET ' . Database::qi('email') . ' = ?, '
                . Database::qi('is_admin') . ' = ?, ' . Database::qi('level') . ' = ?, ' . Database::qi('status') . ' = ?, '
                . Database::qi('reason') . ' = ?, ' . Database::qi('password') . ' = ?, '
                . Database::qi('updated_at') . ' = ? WHERE ' . Database::qi('username') . ' = ?'
            );
            $st->execute([$email, $level === 1 ? 1 : 0, $level, $status, $reason, password_hash($password, PASSWORD_DEFAULT), Database::now(), $username]);
        } else {
            $st = $db->prepare(
                'UPDATE ' . Database::qi('users') . ' SET ' . Database::qi('email') . ' = ?, '
                . Database::qi('is_admin') . ' = ?, ' . Database::qi('level') . ' = ?, ' . Database::qi('status') . ' = ?, '
                . Database::qi('reason') . ' = ?, ' . Database::qi('updated_at') . ' = ? WHERE ' . Database::qi('username') . ' = ?'
            );
            $st->execute([$email, $level === 1 ? 1 : 0, $level, $status, $reason, Database::now(), $username]);
        }
        Response::data(['ok' => true]);
    }

    /** POST users.set-status —— 冻结 / 封禁 / 解封（status: active|frozen|banned，可携带原因） */
    public static function setStatus(): never
    {
        Auth::requireAdmin();
        Auth::verifyCsrf();
        $b = Response::body();
        $username = trim((string)($b['username'] ?? ''));
        $status = trim((string)($b['status'] ?? ''));
        if ($username === '') {
            Response::error('缺少用户名。', 422, 'INVALID_INPUT');
        }
        if (!in_array($status, ['active', 'frozen', 'banned'], true)) {
            Response::error('无效的用户状态。', 422, 'INVALID_INPUT');
        }
        $reason = trim(mb_substr((string)($b['reason'] ?? ''), 0, 500, 'UTF-8'));
        // 恢复正常状态时清空原因
        if ($status === 'active') {
            $reason = '';
        }
        $me = Auth::requireAdmin();
        if (strcasecmp((string)$me['username'], $username) === 0) {
            Response::error('不能修改当前登录账户的状态。', 422, 'INVALID_INPUT');
        }
        $db = Database::pdo();
        $st = $db->prepare('SELECT ' . Database::qi('id') . ' FROM ' . Database::qi('users') . ' WHERE ' . Database::qi('username') . ' = ?');
        $st->execute([$username]);
        if ($st->fetchColumn() === false) {
            Response::error('用户不存在。', 404, 'NOT_FOUND');
        }
        $st = $db->prepare(
            'UPDATE ' . Database::qi('users') . ' SET ' . Database::qi('status') . ' = ?, ' . Database::qi('reason') . ' = ?, '
            . Database::qi('updated_at') . ' = ? WHERE ' . Database::qi('username') . ' = ?'
        );
        $st->execute([$status, $reason, Database::now(), $username]);
        Response::data(['ok' => true, 'status' => $status, 'reason' => $reason]);
    }

    /** POST users.delete —— 按用户名删除 */
    public static function delete(): never
    {
        Auth::requireAdmin();
        Auth::verifyCsrf();
        $b = Response::body();
        $username = trim((string)($b['username'] ?? ''));
        if ($username === '') {
            Response::error('缺少用户名。', 422, 'INVALID_INPUT');
        }
        $me = Auth::requireAdmin();
        if (strcasecmp((string)$me['username'], $username) === 0) {
            Response::error('不能删除当前登录的账户。', 422, 'INVALID_INPUT');
        }
        $st = Database::pdo()->prepare('DELETE FROM ' . Database::qi('users') . ' WHERE ' . Database::qi('username') . ' = ?');
        $st->execute([$username]);
        Response::data(['ok' => true]);
    }

    // ==================== 个人资料（当前登录用户） ====================

    /** GET user.me —— 完整个人资料（含邮箱，仅供本人） */
    public static function me(): never
    {
        $u = Auth::requireLogin();
        Response::data(array_merge((array)Auth::publicUser($u), [
            'email' => (string)($u['email'] ?? ''),
        ]));
    }

    /** GET user.profile?username= —— 用户公开主页（含贡献与活跃图；本人额外返回邮箱） */
    public static function profile(): never
    {
        $username = trim((string)($_GET['username'] ?? ''));
        if ($username === '') {
            Response::error('缺少用户名。', 422, 'INVALID_INPUT');
        }
        $st = Database::pdo()->prepare('SELECT * FROM ' . Database::qi('users') . ' WHERE ' . Database::qi('username') . ' = ?');
        $st->execute([$username]);
        $u = $st->fetch();
        if (!$u) {
            Response::error('用户不存在。', 404, 'NOT_FOUND');
        }
        $me = Auth::user();
        $isSelf = $me !== null && (int)$me['id'] === (int)$u['id'];
        $user = Auth::publicUser($u);
        if ($isSelf) {
            $user['email'] = (string)$u['email'];
        }
        Response::data([
            'user'          => $user,
            'is_self'       => $isSelf,
            'contributions' => self::contributionsOf((int)$u['id']),
            'activity'      => self::activityOf((int)$u['id']),
        ]);
    }

    /** POST user.profile —— 更新个人信息（昵称/头像/介绍/邮箱/社交连接） */
    public static function updateProfile(): never
    {
        Auth::verifyCsrf();
        $u = Auth::requireActive();
        $b = Response::body();

        $nickname = trim(mb_substr((string)($b['nickname'] ?? ''), 0, 32, 'UTF-8'));
        $bio      = trim(mb_substr((string)($b['bio'] ?? ''), 0, 500, 'UTF-8'));
        $avatar   = trim(mb_substr((string)($b['avatar'] ?? ''), 0, 500, 'UTF-8'));
        $email    = trim((string)($b['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('邮箱格式不正确。', 422, 'INVALID_INPUT');
        }

        // 社交连接：仅保留支持的键，值截断为 URL 字符串
        $allowed = ['qq', 'wechat', 'bilibili', 'youtube', 'github', 'x'];
        $socials = [];
        foreach ($allowed as $k) {
            $v = trim((string)($b['socials'][$k] ?? ''));
            if ($v !== '') {
                $socials[$k] = mb_substr($v, 0, 500, 'UTF-8');
            }
        }
        $socialJson = json_encode($socials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $st = Database::pdo()->prepare(
            'UPDATE ' . Database::qi('users') . ' SET '
            . Database::qi('nickname') . ' = ?, ' . Database::qi('bio') . ' = ?, ' . Database::qi('avatar') . ' = ?, '
            . Database::qi('email') . ' = ?, ' . Database::qi('socials') . ' = ?, ' . Database::qi('updated_at') . ' = ? '
            . 'WHERE id = ?'
        );
        $st->execute([$nickname, $bio, $avatar, $email, $socialJson, Database::now(), (int)$u['id']]);
        Auth::forgetUser();
        Response::data(['user' => Auth::publicUser(Auth::user())]);
    }

    /** GET user.activity —— 当前用户最近 365 天每日编辑数 */
    public static function activity(): never
    {
        $u = Auth::requireLogin();
        Response::data(['counts' => self::activityOf((int)$u['id'])]);
    }

    /** POST user.avatar —— 上传头像（multipart；前端已裁剪压缩为 160px WEBP） */
    public static function avatarUpload(): never
    {
        Auth::verifyCsrf();
        $u = Auth::requireActive();
        if (empty($_FILES['avatar']) || ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('未收到图片文件。', 422, 'INVALID_INPUT');
        }
        $tmp = $_FILES['avatar']['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            Response::error('无效的图片文件。', 422, 'INVALID_INPUT');
        }
        $info = @getimagesize($tmp);
        if ($info === false || !in_array($info[2], [IMAGETYPE_WEBP, IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF], true)) {
            Response::error('不支持的图片格式。', 422, 'INVALID_INPUT');
        }
        $base = (string)(app_config()['uploads']['dir'] ?? dirname(__DIR__) . '/uploads');
        $dir = rtrim($base, '/\\') . '/avatars';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            Response::error('无法创建头像目录，请检查目录权限。', 500, 'UPLOAD_FAILED');
        }
        $filename = 'u' . (int)$u['id'] . '.webp';
        if (!@move_uploaded_file($tmp, $dir . '/' . $filename)) {
            Response::error('保存头像失败，请检查目录权限。', 500, 'UPLOAD_FAILED');
        }
        $st = Database::pdo()->prepare(
            'UPDATE ' . Database::qi('users') . ' SET ' . Database::qi('avatar') . ' = ?, ' . Database::qi('updated_at') . ' = ? WHERE ' . Database::qi('id') . ' = ?'
        );
        $st->execute(['/api/uploads/avatars/' . $filename, Database::now(), (int)$u['id']]);
        Auth::forgetUser();
        Response::data(['user' => Auth::publicUser(Auth::user())]);
    }

    /** GET user.contributions —— 当前用户贡献列表 */
    public static function contributions(): never
    {
        $u = Auth::requireLogin();
        Response::data(self::contributionsOf((int)$u['id']));
    }

    // ==================== 内部工具 ====================

    /** 指定用户的贡献列表 */
    private static function contributionsOf(int $userId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT p.' . Database::qi('tag') . ', p.' . Database::qi('title') . ', '
            . 'MAX(r.' . Database::qi('created_at') . ') AS ' . Database::qi('last_at') . ', '
            . 'COUNT(*) AS ' . Database::qi('edits')
            . ' FROM ' . Database::qi('revisions') . ' r'
            . ' JOIN ' . Database::qi('pages') . ' p ON p.' . Database::qi('id') . ' = r.' . Database::qi('page_id')
            . ' WHERE r.' . Database::qi('user_id') . ' = ?'
            . ' GROUP BY p.' . Database::qi('id') . ', p.' . Database::qi('tag') . ', p.' . Database::qi('title')
            . ' ORDER BY ' . Database::qi('last_at') . ' DESC'
        );
        $st->execute([$userId]);
        return array_map(fn($r) => [
            'tag'        => (string)$r['tag'],
            'title'      => (string)$r['title'],
            'edits'      => (int)$r['edits'],
            'updated_at' => (string)$r['last_at'],
        ], $st->fetchAll());
    }

    /** 指定用户的活跃图数据 */
    private static function activityOf(int $userId): array
    {
        $since = date('Y-m-d 00:00:00', time() - 364 * 86400);
        $st = Database::pdo()->prepare(
            'SELECT ' . Database::qi('created_at') . ' FROM ' . Database::qi('revisions')
            . ' WHERE ' . Database::qi('user_id') . ' = ? AND ' . Database::qi('created_at') . ' >= ?'
        );
        $st->execute([$userId, $since]);
        $counts = [];
        foreach ($st->fetchAll() as $r) {
            $d = substr((string)$r['created_at'], 0, 10);
            $counts[$d] = (int)($counts[$d] ?? 0) + 1;
        }
        return $counts;
    }
}
