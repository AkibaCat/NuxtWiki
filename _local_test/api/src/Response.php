<?php
declare(strict_types=1);

/** HTTP 响应助手 */
final class Response
{
    /** 输出 JSON 并结束 */
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** 成功响应（包一层 data） */
    public static function data(mixed $data, int $status = 200): never
    {
        self::json(['ok' => true, 'data' => $data], $status);
    }

    /** 错误响应 */
    public static function error(string $message, int $status = 400, string $code = 'ERROR'): never
    {
        self::json(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }

    /** 输出 XML（RSS） */
    public static function xml(string $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/xml; charset=utf-8');
        echo $body;
        exit;
    }

    /** 输出文件（附件下载 / 图片预览） */
    public static function file(string $path, string $mime, ?string $downloadName = null, bool $inline = true): never
    {
        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        if ($downloadName !== null) {
            $disp = $inline ? 'inline' : 'attachment';
            header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $downloadName) . '"');
        }
        readfile($path);
        exit;
    }

    /** 以附件形式下载文本内容（备份导出） */
    public static function download(string $content, string $filename, string $mime = 'application/octet-stream'): never
    {
        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . (string)strlen($content));
        echo $content;
        exit;
    }

    /** 读取 JSON 请求体 */
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
