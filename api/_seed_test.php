<?php
// 临时种子脚本：设置管理员与首页内容（测试后删除）
require __DIR__ . '/bootstrap.php';

$db = Database::pdo();

// 1. 确保存在管理员
$st = $db->prepare('SELECT id FROM users WHERE username = ?');
$st->execute(['admin']);
if (!$st->fetch()) {
    $db->prepare(
        "INSERT INTO users (username, email, password, is_admin, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)"
    )->execute(['admin', 'admin@example.com', password_hash('admin123', PASSWORD_DEFAULT), Database::now(), Database::now()]);
    echo "admin created\n";
} else {
    echo "admin exists\n";
}

// 2. 确保存在测试普通用户
$st = $db->prepare('SELECT id FROM users WHERE username = ?');
$st->execute(['alice']);
if (!$st->fetch()) {
    $db->prepare(
        "INSERT INTO users (username, email, password, is_admin, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)"
    )->execute(['alice', 'alice@example.com', password_hash('alice123', PASSWORD_DEFAULT), Database::now(), Database::now()]);
    echo "alice created\n";
}

// 2.5 重置 admin 密码为 admin123（测试用）
$st = $db->prepare('UPDATE users SET password = ? WHERE username = ?');
$st->execute([password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
echo "admin password reset\n";

// 3. 首页内容（含各种 wiki 标记）
$st = $db->prepare('SELECT id FROM pages WHERE tag = ?');
$st->execute(['HomePage']);
if (!$st->fetch()) {
    $body = <<<WIKI
# 欢迎来到 NuxtWiki

一个**轻量级** Wiki 系统，基于 *Nuxt* 与 __PHP/MySQL__ 开发。

## 功能特性

- 页面编辑与修订历史
  - 版本对比（diff）
  - 回滚
- 页面订阅与邮件通知
- 访问控制（ACL）
- 注册码注册
- 个人资料与贡献统计

## 快速开始

| 操作 | 说明 |
| --- | --- |
| 创建页面 | 点击「创建此页面」按钮 |
| 编辑 | 使用 [[语法帮助|GrammarHelp]] 中的语法 |
| 搜索 | 在顶部搜索框中输入关键词 |

---

代码示例：

```php
echo "Hello, NuxtWiki!";
```

> 千里之行，始于足下。
WIKI;
    $db->prepare(
        "INSERT INTO pages (tag, title, body, comment, user_id, created_by, created_at, updated_at, revision, acl_read, acl_write, hits) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, '*', '*', 0)"
    )->execute(['HomePage', '欢迎来到 NuxtWiki', $body, '初始版本', 1, 1, Database::now(), Database::now()]);
    $pageId = Database::lastInsertId();
    $db->prepare(
        "INSERT INTO revisions (page_id, tag, title, body, comment, user_id, revision, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)"
    )->execute([$pageId, 'HomePage', '欢迎来到 NuxtWiki', $body, '初始版本', 1, Database::now()]);
    echo "HomePage created\n";
} else {
    echo "HomePage exists\n";
}

// 4. 一个普通内容页
foreach ([
    ['GrammarHelp', '语法帮助', "# 语法帮助\n\n本页介绍本 Wiki 支持的 Markdown 编辑语法。\n\n## 标题\n\n使用 `#`、`##`、`###` 等标记标题。\n\n## 文本样式\n\n- **加粗**：`**文本**`\n- *斜体*：`*文本*`\n- ~~删除线~~：`~~文本~~`\n- `行内代码`：反引号包裹\n\n## 链接\n\n内部链接：`[[页面名]]`，如 [[HomePage]]\n\n外部链接：`[文字](https://example.com)`\n\n## 代码块\n\n```js\nconsole.log('Hello');\n```\n\n## 表格\n\n| 列一 | 列二 |\n| --- | --- |\n| A | B |"],
] as [$tag, $title, $body]) {
    $st = $db->prepare('SELECT id FROM pages WHERE tag = ?');
    $st->execute([$tag]);
    if (!$st->fetch()) {
        $db->prepare(
            "INSERT INTO pages (tag, title, body, comment, user_id, created_by, created_at, updated_at, revision, acl_read, acl_write, hits) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, '*', '*', 0)"
        )->execute([$tag, $title, $body, '初始版本', 1, 1, Database::now(), Database::now()]);
        $pageId = Database::lastInsertId();
        $db->prepare(
            "INSERT INTO revisions (page_id, tag, title, body, comment, user_id, revision, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)"
        )->execute([$pageId, $tag, $title, $body, '初始版本', 1, Database::now()]);
        echo "$tag created\n";
    }
}

echo "done\n";
