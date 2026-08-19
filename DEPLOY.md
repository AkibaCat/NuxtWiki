# NuxtWiki 部署指南

NuxtWiki 采用 **纯静态 SPA 前端（Nuxt 4）+ PHP API** 架构，可部署在 **Kangle（easypanel）/ Apache / Nginx** 等支持 PHP 的虚拟主机上：

- **前端**：Nuxt `ssr: false` 的静态构建产物，纯 HTML/JS/CSS，服务器**无需 Node 运行时**。
- **后端**：PHP API（`api/` 目录，PDO 直连数据库），通过 `index.php?r=<controller>.<action>` 路由。
- **数据库**：生产使用 **MySQL**，本地开发使用 **SQLite**；两套 schema 均已提供，安装向导会自动建表。

> 本文按「先本地打包、后上传部署」的流程编写，兼容 Kangle / Apache / Nginx / 宝塔（Nginx）等常见环境。

---

## 1. 环境要求

| 组件   | 要求                                                                 |
|--------|----------------------------------------------------------------------|
| 服务器 | Kangle（easypanel 面板）或其他支持 PHP 的 Web 服务器                  |
| PHP    | ≥ 8.1，必选扩展：`pdo_mysql`、`mbstring`、`fileinfo`<br>可选扩展：`openssl`、`curl`、`gd`、`zip` |
| MySQL  | 5.7+ / 8.0，字符集 `utf8mb4`（排序规则 `utf8mb4_unicode_ci`）         |
| 可写目录 | `api/data/`、`api/uploads/`（框架运行时写入数据与上传附件）            |

> 本地开发使用 SQLite（需 `pdo_sqlite`），生产环境使用 MySQL。

---

## 2. 站点目录结构

将构建产物与 API 放于**同一站点根目录**（假设部署在域名根路径 `/` 下）：

```
站点根目录/
├── index.html          ← SPA 入口（Nuxt 静态构建产物）
├── 200.html            ← SPA 兜底
├── 404.html
├── _nuxt/              ← 前端 JS/CSS 资源
├── _fonts/             ← 字体资源
├── account/  admin/  pages/  recent/  search/
├── register/  login/  settings/       ← 静态路由壳（由 nuxt generate 生成）
├── .htaccess           ← 伪静态（SPA 兜底）规则
└── api/                ← PHP 后端
    ├── index.php       ← API 入口
    ├── install.php     ← 安装向导
    ├── bootstrap.php
    ├── schema.mysql.sql / schema.sqlite.sql
    ├── seed/           ← 初始页面内容（welcome.md / grammar-help.md）
    ├── src/            ← 核心类与控制器
    ├── config.php      ← 安装后自动生成（含数据库连接信息）
    ├── data/           ← 运行期数据（SQLite / 备份），需可写
    └── uploads/        ← 附件上传目录，需可写
```

> ⚠️ 前端通过 **根路径绝对地址** 访问资源（`/api/index.php`、`/_nuxt/...`），因此本站需部署在**域名根路径**。若需部署在子目录（如 `example.com/subdir/`），需重新构建并配置 `app.baseURL` 与 `NUXT_PUBLIC_API_BASE`。

---

## 3. 构建打包（在本地开发机执行）

### 3.1 方式一：一键脚本（推荐）

仓库提供 `build-deploy.sh`，自动完成「安装依赖 → 构建前端 → 组装后端 → 打包」：

```bash
./build-deploy.sh
```

- 产物：`deploy/nuxtwiki-<版本>.tar.gz`（默认版本为时间戳，可用 `VERSION=1.2.0` 指定）。
- 压缩包**不嵌套外层目录**，解压即为站点根目录内容。
- 脚本会在源头排除运行期文件：`api/data`、`api/uploads`、`api/config.php`（这些会在安装时自动生成）。

### 3.2 方式二：手动构建

```bash
pnpm install
pnpm generate
```

构建产物位于 `.output/public/`。将其中**所有内容**（含 `index.html`、`200.html`、`404.html`、`.htaccess`、`_nuxt/`、各路由壳）复制到站点根目录，再将整个 `api/` 目录复制到 `站点根目录/api/`（务必保留 `api/seed/`）。

> ⚠️ 必须使用 **`nuxt generate`**（SPA 模式）。它会生成 `index.html`、`200.html`、`404.html` 及各静态路由壳；`nuxt build`（node-server 预设）不会生成 `index.html`，无法用于纯静态部署。

### 3.3 通过 CI 获取产物

每次 `git push` 后，`.github/workflows/ci.yml` 会自动构建并上传部署包：

- 仓库 → **Actions** → 选择最新一次运行 → 底部 **Artifacts** → 下载 `nuxtwiki-deploy` 即可。
- 正式版本也可在仓库的 **Releases** 页面获取：Releases 页 → 对应版本标签 → **Assets**（源码压缩包与随附的部署包）。

### 3.4 通过 gh-pages 分支拉取

CI 构建完成后还会把产物**直接推送到 `gh-pages` 分支**（由 `peaceiris/actions-gh-pages` 完成），因此可直接拉取该分支的内容部署，无需在本地构建：

```bash
git fetch origin gh-pages
git checkout -b gh-pages origin/gh-pages   # 首次：基于远程分支创建本地分支
# 或已有本地分支时：git checkout gh-pages && git pull origin gh-pages
```

- 该分支的根目录即站点根目录内容（`index.html`、`api/`、`_nuxt/` 等），拉取后上传到服务器站点根目录即可。
- 由于 `gh-pages` 分支使用 `force_orphan: true` 每次完全重建，**不要**在它上面直接修改再推送，否则会被下一次 CI 覆盖；对站点内容的长期修改应回到 `main` 分支维护。
- 该分支通常用于「服务器通过 git 拉取部署」的自动化场景
- 若只是下载部署包，用上文 3.3 的 Artifacts 或从仓库 **Releases** 页面的 Assets 下载更方便。

---

## 4. 上传与部署

1. 获取部署包 `nuxtwiki-<版本>.tar.gz`（可从仓库 **Releases** 页面的 Assets 下载，或用 3.3 的 CI Artifacts / 本地 `./build-deploy.sh` 生成），然后上传到服务器（FTP / SFTP / 面板文件管理器 / `scp`）。
2. 在服务器站点根目录解压（`tar -xzf nuxtwiki-<版本>.tar.gz`），确保 `index.html`、`api/`、`.htaccess` 直接落在根目录下。
3. 无需手动导入 schema，安装向导会自动执行建表。
4. 确保 `api/`、`api/data/`、`api/uploads/` 对 PHP 进程**可写**（见第 5.5 节）。
5. 浏览器访问 `http://你的域名/api/install.php` 完成安装（见第 5 节）。

---

## 5. 生产安装与配置

### 5.1 配置 PHP 处理（Kangle）

在 easypanel 面板为站点选择 **PHP 版本（8.1+）**，Kangle 会通过 FastCGI 自动处理 `.php` 请求，无需额外配置。

需确认服务器 PHP 已启用 `pdo_mysql`、`mbstring`、`fileinfo` 等扩展（在服务器 `php.ini` 中取消对应 `extension` 行注释）。

### 5.2 配置伪静态（SPA 兜底）

在站点根目录创建 `.htaccess`（Kangle 支持 Apache 风格重写）：

> 此文件已在 `public` 目录与部署包里包含，无需手动创建。

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
- `/api/index.php`、`/api/install.php`、`/api/uploads/...` 都是真实文件，`RewriteCond %{REQUEST_FILENAME} !-f` 已保证它们不会被重写；
- `!^/api` 条件用于拦截「不存在的 /api 路径」，避免被错误兜底到 SPA；
- 若面板要求把重写规则配置在控制面板，将以上 `RewriteCond` / `RewriteRule` 按面板格式填入即可。
- **Nginx** 用户请将上述规则等价转换为 `try_files $uri $uri/ /index.html;`（并确保 `/api` 下的请求交给 PHP、不落入 SPA）。

### 5.3 创建 MySQL 数据库

```sql
CREATE DATABASE nuxtwiki DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nuxtwiki'@'localhost' IDENTIFIED BY '你的强密码';
GRANT ALL PRIVILEGES ON nuxtwiki.* TO 'nuxtwiki'@'localhost';
FLUSH PRIVILEGES;
```

> 无需手动导入 schema，安装向导会自动执行 `schema.mysql.sql`。

### 5.4 运行安装向导

浏览器访问 `http://你的域名/api/install.php`：

1. **环境检查**应全部通过（`PDO_MYSQL` 必须为 ✓，各目录可写为 ✓）；
2. 数据库类型选择 **MySQL**，填写主机 / 端口 / 库名 / 账号 / 密码；
3. 填写站点名称、首页页面名与管理员账号密码；
4. 点击「开始安装」。

安装完成后自动生成 `api/config.php`（写入 MySQL 连接信息并建表），创建管理员账号，并从 `api/seed/` 导入两个初始页面（首页、语法帮助）。最后返回网站首页。

> 初始页面内容取自 `api/seed/welcome.md` 与 `api/seed/grammar-help.md`（站名通过 `{{SITE_NAME}}` 占位符注入），如需自定义默认内容，修改对应 `.md` 后重新安装即可。

### 5.5 设置目录权限

确保 Kangle / PHP 进程对以下目录有**写**权限：

- `api/data/` —— SQLite / 备份文件
- `api/uploads/` —— 附件上传

Linux 可执行 `chown -R <php用户>:<组> api/data api/uploads`、`chmod -R u+w api/data api/uploads`；
Windows 可在资源管理器中右键目录 → 属性 → 安全，授予运行账号「修改」权限。

### 5.6 验证测试

- 访问首页 `http://你的域名/`，应能正常渲染并显示导航；
- 访问 `http://你的域名/Home`，应能通过 SPA 兜底正常打开；
- 用管理员账号登录，新建页面、上传附件；
- 访问 `http://你的域名/api/index.php?r=admin.stats`（未登录应返回 401/403）；
- 访问 `http://你的域名/api/index.php?r=page.get&tag=HomePage`，应返回页面 JSON 数据。

---

## 6. 宝塔面板（Nginx）专属部署

本小节针对 **宝塔面板 + Nginx + PHP-FPM + MySQL** 的常见环境。若使用 Kangle 或 Apache，请按上文第 5 节操作。

### 6.1 安装运行环境

在宝塔「软件商店」安装：

| 软件  | 版本要求 | 备注                         |
|-------|----------|------------------------------|
| Nginx | 任意较新版本 | 宝塔默认 Web 服务器          |
| PHP   | ≥ 8.1     | 8.1 / 8.2 / 8.3 均可           |
| MySQL | 5.7+ / 8.0 | 字符集 `utf8mb4`            |

到「网站 → PHP 设置 → 安装扩展」勾选 **`pdo_mysql`、`mbstring`、`fileinfo`**（可选 `openssl`、`curl`、`gd`、`zip`）。

### 6.2 添加站点并上传部署包

1. 本地生成部署包：`./build-deploy.sh`（或通过 Releases / CI Actions 下载）。
2. 宝塔「网站 → 添加站点」，绑定域名，创建站点。
3. 将 `nuxtwiki-<版本>.tar.gz` 上传到服务器，并在站点根目录 `www/wwwroot/<站点名>/` 下解压，确保 `index.html`、`api/`、`_nuxt/` 直接落在根目录。
4. 在宝塔「网站 → 设置 → 网站目录」将**运行目录**设为站点**根目录**（`/`）。前端以根路径绝对地址访问资源，必须承载在根路径；若需子目录部署，见上文第 2 节备注。

### 6.3 创建数据库并运行安装向导

1. 宝塔「数据库」新建数据库，字符集选 `utf8mb4` → 排序规则 `utf8mb4_unicode_ci`。
2. 访问 `http://你的域名/api/install.php`，数据库类型选择 **MySQL**，填写主机 / 端口 / 库名 / 账号 / 密码，完成安装。

### 6.4 配置 Nginx 伪静态（SPA 兜底）

`.htaccess` 仅对 Apache 生效，Nginx 需在宝塔「网站 → 设置 → 伪静态」中配置。核心是：**真实文件与 `/api` 下的 PHP 请求不入 SPA 兜底**：

```nginx
location / {
    # 存在的真实文件/目录（_nuxt、_fonts、api/uploads 等）直接服务
    if (-e $request_filename) { break; }
    # 其余前端路由（/页面名、/页面名/edit、/admin 等）兜底到 SPA 入口
    rewrite ^(.*)$ /index.html last;
}

# 关键：/api 下的 PHP 请求交给 PHP-FPM，避免被上述 rewrite 兜底到 SPA
location ~ ^/api/.+\.php$ {
    include enable-php.conf;
}
```

> 要点：宝塔通过 `include enable-php.conf;` 启用 PHP-FPM 解析，务必保留；该规则与第 5.2 节 Apache `.htaccess` 的 `RewriteCond !^/api` 逻辑等价。

### 6.5 目录权限

确保 `api/data/`、`api/uploads/` 对 PHP-FPM 运行用户（通常为 `www`）可写：

- 宝塔「文件」中选中这两个目录 → 右键「权限」，属主设为 `www`，并勾选写权限；
- 或终端执行：

```bash
chown -R www:www api/data api/uploads
chmod -R u+w api/data api/uploads
```

---

## 7. 常见问题

| 现象 | 排查 |
|------|------|
| 访问 `/页面名` 404 | 未配置伪静态兜底，检查 `.htaccess` 是否正确上传且 Kangle 已启用重写模块 |
| 页面能开但数据请求失败 | 检查 `/api/index.php` 是否可访问；`config.php` 的 MySQL 连接是否正确；`pdo_mysql` 是否启用 |
| 安装向导提示「PDO_MYSQL 缺失」 | 在服务器 `php.ini` 启用 `pdo_mysql` 后重启 Kangle PHP 服务 |
| 附件上传失败 | 检查 `api/uploads/` 写权限与 `upload_max_filesize` / `post_max_size` 配置 |
| 登录后刷新失效 | 确认 PHP session 目录可写；Cookie 域名与访问域名一致；生产环境建议启用 HTTPS |
| 页面能打开但样式/图片异常 | 确认站点的 `_nuxt`、`_fonts`、`api/uploads` 路径未被伪静态重写 |
| 部署包解压后目录结构不对 | 确认解压的是「无外层目录」的产物包，`index.html`、`api/`、`.htaccess` 应直接位于根目录 |

---

## 8. 生产环境建议

- **全程启用 HTTPS**，并确认 `api/config.php` 中 `security` 段 session 配置与站点协议一致。
- 修改 `api/config.php` 中 `mail.from` 与 SMTP 配置，启用邮件通知。
- 定期在管理后台「备份」页导出数据备份，迁移时可用「导入」功能恢复。
- **不要提交 `api/config.php` 到公开仓库**（`.gitignore` 已忽略），其中包含数据库凭据。

## 9. 更新升级

1. 重新构建并生成最新部署包（`./build-deploy.sh` 或通过 CI Artifacts 下载）。
2. 覆盖上传新包内容到站点根目录（**不要删除** `api/config.php`、`api/data/`、`api/uploads/`，以保留配置与数据）。
3. 框架会在访问时自动执行幂等的迁移（新增表/列），通常无需手动操作。
4. 验证页面与历史数据是否正常。

> 升级不会覆盖已存在的数据库内容；若希望更新初始页面（首页 / 语法帮助）的默认正文，请直接在站点内编辑对应页面。