# NuxtWiki 部署说明

NuxtWiki 采用 **纯静态 SPA 前端（Nuxt 4 + Nuxt UI）+ PHP API** 架构，可在
**Kangle（easypanel）/ Apache / Nginx** 等支持 PHP 的虚拟主机上运行。

- 前端：Nuxt `ssr: false` 的静态构建产物，纯 HTML/JS/CSS，无需 Node 运行时。
- 后端：PHP API（`api/` 目录，PDO 直连数据库），通过 `index.php?r=<controller>.<action>` 路由。
- 数据库：MySQL（生产）/ SQLite（本地开发），安装向导自动建表。

---

## 1. 环境要求

| 组件   | 要求                                                                 |
|--------|----------------------------------------------------------------------|
| 服务器 | Kangle（easypanel 面板）或其他支持 PHP 的 Web 服务器                  |
| PHP    | ≥ 8.1，启用扩展：`pdo_mysql`、`mbstring`、`fileinfo`（可选：`openssl`、`curl`、`gd`、`zip`） |
| MySQL  | 5.7+ / 8.0，字符集 `utf8mb4`（排序规则 `utf8mb4_unicode_ci`）         |
| 目录   | `api/data/`、`api/uploads/` 需可写                                    |

> 本地开发使用 SQLite（需 `pdo_sqlite` / `sqlite3`），生产环境使用 MySQL。
> 两套 schema 均已提供（`api/schema.mysql.sql` / `api/schema.sqlite.sql`），安装向导会自动执行。

---

## 2. 站点目录结构

将静态产物与 API 放在同一站点根目录（假设部署在域名根路径 `/` 下）：

```
站点根目录/
├── index.html          ← SPA 入口（Nuxt 静态构建产物）
├── 200.html            ← SPA 兜底
├── 404.html
├── _nuxt/              ← 前端 JS/CSS 资源
├── _fonts/             ← 字体资源
├── account/  admin/  create/  login/  pages/  recent/
├── register/  search/  random/   ← 静态路由壳（由 nuxt generate 生成）
├── .htaccess           ← 伪静态（SPA 兜底）规则
└── api/                ← PHP 后端（整个 api 目录复制过来）
    ├── index.php       ← API 入口
    ├── install.php     ← 安装向导
    ├── config.php      ← 安装后生成（含数据库连接信息）
    ├── bootstrap.php
    ├── schema.mysql.sql / schema.sqlite.sql
    ├── src/            ← 核心类与控制器
    ├── data/           ← 运行时数据（SQLite / 备份），需可写
    └── uploads/        ← 附件上传目录，需可写
```

> 说明：前端通过 `/api/index.php` 与 `/_nuxt/...` 等**根路径绝对地址**访问资源，
> 因此本站需部署在域名根路径。若需部署在子目录（如 `example.com/subdir/`），
> 需重新构建并配置 `app.baseURL` 与 `NUXT_PUBLIC_API_BASE`。

---

## 3. 部署步骤

### 3.1 构建前端静态产物

在开发机（已安装 Node.js + pnpm）执行：

```bash
pnpm install
pnpm generate
```

构建产物位于 `.output/public/`。把该目录下**所有内容**复制到站点根目录。

> 必须使用 `nuxt generate`（SPA 模式），它会生成 `index.html`、`200.html`、
> `404.html` 及各静态路由入口壳。`nuxt build`（node-server 预设）不会生成
> `index.html`，无法用于纯静态部署。

### 3.2 复制 PHP 后端

将整个 `api/` 目录复制到站点根目录（保持目录名 `api`，与前端约定一致）。

### 3.3 配置伪静态（SPA 兜底）

在站点根目录创建 `.htaccess`（Kangle 支持 Apache 风格重写）：

```apache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /

# API 请求交给 PHP 处理（不重写）
RewriteCond %{REQUEST_URI} !^/api(/|$)

# 已存在的文件/目录（_nuxt、_fonts、favicon、uploads 等）不重写
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# 其余路径（/页面名、/页面名/edit、/admin 等前端路由）全部交给 SPA 入口
RewriteRule ^(.*)$ /index.html [L]
</IfModule>
```

要点：
- `/api/index.php`、`/api/install.php`、`/api/uploads/...` 都是真实文件，
  `RewriteCond %{REQUEST_FILENAME} !-f` 已保证它们不会被重写；
- `!^/api` 条件用于拦截“不存在的 /api 路径”，避免被错误兜底到 SPA；
- 若面板要求把重写规则配置在控制面板（虚拟主机 → 重写规则），将以上
  `RewriteCond` / `RewriteRule` 按面板格式填入即可。

### 3.4 配置 PHP 处理

在 easypanel 面板为站点选择 PHP 版本（8.1+），Kangle 会通过 FastCGI 自动处理
`.php` 请求，无需额外配置。

需确认服务器 PHP 已启用 `pdo_mysql`、`mbstring`、`fileinfo` 等扩展（在服务器
php.ini 中取消对应 `extension` 注释）。

### 3.5 创建 MySQL 数据库

```sql
CREATE DATABASE nuxtwiki DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nuxtwiki'@'localhost' IDENTIFIED BY '你的强密码';
GRANT ALL PRIVILEGES ON nuxtwiki.* TO 'nuxtwiki'@'localhost';
FLUSH PRIVILEGES;
```

> 无需手动导入 schema，安装向导会自动执行 `schema.mysql.sql`。

### 3.6 运行安装向导

浏览器访问 `http://你的域名/api/install.php`：

1. 环境检查应全部通过（`PDO_MYSQL` 必须为 ✓）；
2. 数据库类型选择 **MySQL**，填写主机 / 端口 / 库名 / 账号 / 密码；
3. 填写站点名称、首页页面名与管理员账号密码；
4. 点击「开始安装」。

安装完成后自动生成 `api/config.php`（写入 MySQL 连接信息并建表），创建管理员账号，
并写入两个初始页面（首页、语法帮助）。最后返回网站首页。

### 3.7 设置目录权限

确保 Kangle / PHP 进程对以下目录有**写**权限：

- `api/data/`（SQLite / 备份文件）
- `api/uploads/`（附件上传）

Windows 下可在资源管理器中右键目录 → 属性 → 安全，授予运行账号「修改」权限。

### 3.8 验证测试

- 访问首页 `http://你的域名/`，应能正常渲染并显示导航；
- 访问 `http://你的域名/HomePage`，应能通过 SPA 兜底正常打开；
- 用管理员账号登录，新建页面、上传附件；
- 访问 `http://你的域名/api/index.php?r=admin.stats`（未登录应返回 401/403）；
- 访问 `http://你的域名/api/index.php?r=page.get&tag=HomePage`，应返回页面 JSON 数据。

---

## 4. 常见问题

| 现象 | 排查 |
|------|------|
| 访问 `/页面名` 404 | 未配置伪静态兜底，检查 `.htaccess` 是否正确上传且 Kangle 已启用重写模块 |
| 页面能开但数据请求失败 | 检查 `/api/index.php` 是否可访问；`config.php` 的 MySQL 连接是否正确；`pdo_mysql` 是否启用 |
| 安装向导提示「PDO_MYSQL 缺失」 | 在服务器 php.ini 启用 `pdo_mysql` 后重启 Kangle PHP 服务 |
| 附件上传失败 | 检查 `api/uploads/` 写权限与 `upload_max_filesize` / `post_max_size` 配置 |
| 登录后刷新失效 | 确认 PHP session 目录可写，Cookie 域名与访问域名一致（生产环境建议 HTTPS） |
| 页面能打开但样式/图片异常 | 确认站点的 `_nuxt`、`_fonts`、`api/uploads` 路径未被伪静态重写 |

---

## 5. 生产环境建议

- 全程启用 HTTPS，并确认 `api/config.php` 中 `security` 段的 session 配置与站点协议一致；
- 修改 `api/config.php` 中 `mail.from` 与 SMTP 配置，启用邮件通知；
- 定期在管理后台「备份」页导出数据备份，迁移时可用「导入」功能恢复；
- 数据库凭据存放在 `api/config.php`，请勿提交到公开仓库（`.gitignore` 已忽略）。
