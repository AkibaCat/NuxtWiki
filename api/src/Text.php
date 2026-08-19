<?php
declare(strict_types=1);

/** 文本工具：页面名规范化 / 摘要 / 纯文本 / 行级 diff */
final class Text
{
    /**
     * 页面名（WackoWiki 风格）：保留字母数字、中文、下划线、连字符、点，其余替换为下划线。
     */
    public static function normalizeTag(string $tag): string
    {
        $tag = trim($tag);
        $tag = str_replace(' ', '_', $tag);
        $tag = preg_replace('/[^\p{L}\p{N}_\-\.]+/u', '_', $tag) ?? $tag;
        $tag = trim($tag, '_');
        return mb_substr($tag, 0, 191, 'UTF-8');
    }

    /** 去除 Markdown 标记，得到用于摘要 / RSS 的纯文本 */
    public static function plainText(string $markup, int $max = 300): string
    {
        $t = $markup;
        // 代码块与缩进代码
        $t = preg_replace('/```.*?```/s', ' ', $t) ?? $t;
        $t = preg_replace('/~~~.*?~~~/s', ' ', $t) ?? $t;
        $t = preg_replace('/^ {4,}.*$/m', ' ', $t) ?? $t;
        // 表格行
        $t = preg_replace('/^\|.*$/m', ' ', $t) ?? $t;
        // 标题
        $t = preg_replace('/^ {0,3}#{1,6}[ \t]+/m', '', $t) ?? $t;
        // 引用
        $t = preg_replace('/^ {0,3}>[ \t]?/m', '', $t) ?? $t;
        // 列表与分隔线
        $t = preg_replace('/^ {0,3}(?:[-*+]|\d+[.)])[ \t]+/m', '', $t) ?? $t;
        $t = preg_replace('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/m', ' ', $t) ?? $t;
        // 链接/图片
        $t = preg_replace('/!\[([^\]]*)\]\([^)]*\)/u', '$1', $t) ?? $t;
        $t = preg_replace('/\[\[([^\]|]*)\|([^\]]+)\]\]/u', '$1', $t) ?? $t;
        $t = preg_replace('/\[\[([^\]]+)\]\]/u', '$1', $t) ?? $t;
        $t = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $t) ?? $t;
        $t = preg_replace('/\{\{([^}]*)\}\}/u', '', $t) ?? $t;
        // 行内标记
        $t = preg_replace('/\*\*(.+?)\*\*/u', '$1', $t) ?? $t;
        $t = preg_replace('/~~(.+?)~~/u', '$1', $t) ?? $t;
        $t = preg_replace('/__(.+?)__/u', '$1', $t) ?? $t;
        $t = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '$1', $t) ?? $t;
        $t = preg_replace('/`([^`\n]+)`/u', '$1', $t) ?? $t;
        // HTML 实体与空白归一
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim($t);
        if (mb_strlen($t, 'UTF-8') > $max) {
            $t = mb_substr($t, 0, $max, 'UTF-8') . '…';
        }
        return $t;
    }

    /**
     * 行级 LCS diff，返回统一格式行数组：
     * ['  ' 保留行, '+ ' 新增, '- ' 删除]（前缀即 diff 标记）。
     */
    public static function diff(string $old, string $new): array
    {
        $a = preg_split('/\r\n|\r|\n/', $old) ?: [];
        $b = preg_split('/\r\n|\r|\n/', $new) ?: [];
        if ($a === ['']) $a = [];
        if ($b === ['']) $b = [];

        // 最长公共子序列（按行）
        $n = count($a);
        $m = count($b);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $out = [];
        $i = 0; $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = '  ' . $a[$i];
                $i++; $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $out[] = '- ' . $a[$i];
                $i++;
            } else {
                $out[] = '+ ' . $b[$j];
                $j++;
            }
        }
        while ($i < $n) { $out[] = '- ' . $a[$i]; $i++; }
        while ($j < $m) { $out[] = '+ ' . $b[$j]; $j++; }
        return $out;
    }

    /** 安全的文件名（去掉路径分隔与非法字符） */
    public static function safeFilename(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '_', $name);
        return preg_replace('/[^\w\-. ]+/u', '_', $name) ?? $name;
    }
}
