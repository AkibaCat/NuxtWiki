<?php
declare(strict_types=1);

/**
 * 工作区持久化（沉浸式页面编辑器专用，普通用户及以上可用）。
 * 工作区内容（打开的页面标签 + 各自的草稿）以 JSON 文件形式存于 data 目录，
 * 按用户隔离，用于「开启自动保存后离开自动保存、再次打开自动恢复」。
 */
final class WorkspaceController
{
    /** 校验登录且普通用户及以上（与页面编辑器可用范围一致：1/2/3 均可，0 访客不可） */
    private static function requireUser(): array
    {
        // requireLogin 确保已登录；levelOf 归一化后登录用户必为 1/2/3（0 仅访客）
        return Auth::requireLogin();
    }

    /** 工作区文件路径（data 目录下，按用户 id 隔离） */
    private static function filePath(int $uid): string
    {
        $dir = rtrim((string)(app_config()['uploads']['data_dir'] ?? (__DIR__ . '/../../data')), '/\\');
        return $dir . DIRECTORY_SEPARATOR . 'workspace_' . $uid . '.json';
    }

    /** GET workspace.get —— 读取当前用户的工作区 */
    public static function get(): never
    {
        $user = self::requireUser();
        $path = self::filePath((int)$user['id']);
        $data = ['active_tag' => '', 'tabs' => []];
        if (is_file($path)) {
            $decoded = json_decode((string)@file_get_contents($path), true);
            if (is_array($decoded)) {
                $tabs = [];
                foreach (($decoded['tabs'] ?? []) as $t) {
                    if (!is_array($t)) {
                        continue;
                    }
                    $tabs[] = self::sanitizeTab($t);
                }
                $data = [
                    'active_tag' => (string)($decoded['active_tag'] ?? ''),
                    'tabs'       => $tabs,
                ];
            }
        }
        Response::data($data);
    }

    /** POST workspace.save —— 保存当前用户的工作区 */
    public static function save(): never
    {
        $user = self::requireUser();
        Auth::verifyCsrf();
        $b = Response::body();
        $tabs = [];
        foreach (($b['tabs'] ?? []) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $tabs[] = self::sanitizeTab($t);
        }
        $data = [
            'active_tag' => (string)($b['active_tag'] ?? ''),
            'tabs'       => $tabs,
        ];
        $path = self::filePath((int)$user['id']);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            Response::error('数据目录不可写，无法保存工作区。', 500, 'IO_ERROR');
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!@file_put_contents($path, $json)) {
            Response::error('工作区保存失败，请检查目录权限。', 500, 'IO_ERROR');
        }
        Response::data(['ok' => true]);
    }

    /** 规整单个标签数据，剔除多余字段、限制大小 */
    private static function sanitizeTab(mixed $t): array
    {
        return [
            'tag'           => (string)($t['tag'] ?? ''),
            'title'         => (string)($t['title'] ?? ''),
            'group'         => (string)($t['group'] ?? '默认页面'),
            'body'          => mb_substr((string)($t['body'] ?? ''), 0, 200000),
            'style'         => mb_substr((string)($t['style'] ?? ''), 0, 200000),
            'comment'       => (string)($t['comment'] ?? ''),
            // 页面内容加载进编辑器时的服务器时间（用于下次打开时检测他人新提交）
            'baseUpdatedAt' => (string)($t['baseUpdatedAt'] ?? ''),
        ];
    }
}
