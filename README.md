<h1 align="center">NuxtWiki</h1>

<p align="center">
  <a href="https://nuxt.com/"><img src="https://img.shields.io/badge/Made%20with-Nuxt%204-00DC82?logo=nuxt&labelColor=020420" alt="Made with Nuxt 4"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-%E2%89%A58.1-777BB4?logo=php&labelColor=020420" alt="PHP ≥ 8.1"></a>
  <a href="https://www.mysql.com/"><img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&labelColor=020420" alt="MySQL 5.7+"></a>
</p>

**NuxtWiki** 是一个轻量、快速、开箱即用的个人知识库 / 团队 Wiki。它采用「纯静态 SPA 前端 + PHP API」架构：前端由 Nuxt 构建为纯静态文件，后端使用 PHP 直连数据库，**无需 Node 运行时**即可部署在任何支持 PHP 的虚拟主机（Kangle / Apache / Nginx 等）上——秒开、稳定、易维护。

---

## 功能特性

- **Markdown 编辑**：标准语法 + 代码语法高亮（一键复制）、表格、引用、目录（TOC）、内部链接、图片与附件；支持 HTML 白名单标签内嵌 Markdown，还可用 `[{文本|颜色}]` 自定义文字着色
- **页面编辑器**：沉浸式「编写 + 实时预览」界面，见即所得；侧栏可创建工作区页面并一键跳转编辑
- **版本管理**：每次保存自动保留修订记录，可查看历史、对比版本差异、一键回滚
- **内容发现**：全文搜索、最近更改、随机页面、反向链接、贡献者榜
- **访问控制（ACL）**：按页面为阅读 / 编辑 / 历史 / 对比 / 回链 / 权限 / 贡献者设置独立等级门槛，精细控制谁能看、谁能改
- **用户体系**：注册码开放注册、登录、个人主页（头像 / 简介 / 社交链接 / 活跃度图）、冻结与封禁管理
- **订阅通知**：订阅关注页面，更新后第一时间收到通知
- **站点设置**：主题色一键切换（科技绿 / 天空蓝 / 活力橙 / 忧郁紫 / 中国红 / 柠檬黄 / 樱花粉 / 珊瑚粉 / 酒红色 / 枫叶橙 / 姜黄色 / 薄荷绿 / 墨绿色 / 抹茶绿 / 藏青色 / 雾霾蓝 / 克莱因蓝 / 蒂芙尼蓝 / 香芋紫 / 高级灰 / 奶茶色 / 咖啡棕 / 曜石黑 / 象牙白），浅色 / 深色模式
- **管理后台**：站点统计、站点设置、页面 / 用户 / 注册码管理、数据备份与导入

## 技术栈

| 层   | 技术 |
|------|------|
| 前端 | Nuxt 4 · Nuxt UI v4 · Tailwind CSS v4 · Vue 3 |
| 后端 | PHP ≥ 8.1（扩展：`pdo_mysql`、`mbstring`、`fileinfo`；可选 `openssl` / `curl` / `gd` / `zip`） |
| 数据库 | MySQL 5.7+ / 8.0（生产）· SQLite（本地开发） |

## 架构概览

```
浏览器
  │  https://你域名/           静态 SPA（index.html + _nuxt + _fonts）
  │  https://你域名/api/...     PHP 后端（index.php?r=controller.action）
  ▼
┌────────────────┐       ┌──────────────────────────────────┐
│  静态资源服务器 │       │              PHP 后端            │
│  Nginx/Apache  │       │  index.php → Router → Controller │
│  / Kangle      │       │  PDO 直连数据库                   │
└────────────────┘       └──────────┬───────────────┬───────┘
                                    │               │
                              MySQL（生产）      SQLite（本地）
```

前端通过根路径绝对地址访问资源（`/api/index.php`、`/_nuxt/...`），因此站点需部署在**域名根路径**；如需部署到子目录，请重新构建并配置 `app.baseURL` 与 `NUXT_PUBLIC_API_BASE`。

## 项目结构

```
├── api/                  # PHP 后端
│   ├── index.php         # API 入口（index.php?r=<controller>.<action>）
│   ├── install.php       # 安装向导（环境检查 + 建库 + 创建管理员 + 初始页面）
│   ├── bootstrap.php     # 启动引导（加载配置与核心类）
│   ├── schema.mysql.sql  # MySQL 表结构
│   ├── schema.sqlite.sql # SQLite 表结构
│   ├── seed/             # 安装时的初始页面内容（独立 Markdown 文件）
│   │   ├── welcome.md        # 欢迎页（首页），{{SITE_NAME}} 由向导注入站点名
│   │   └── grammar-help.md   # 语法帮助页
│   ├── config.php        # 配置（安装时生成，已被 .gitignore 忽略）
│   └── src/              # Router / Auth / Database / Settings / Migrate / Controllers
├── app/                  # Nuxt 前端
│   ├── pages/            # 页面路由（[tag]/ 含编辑/历史/对比/ACL/回链/贡献者、搜索、设置…）
│   ├── components/       # WikiView 渲染器、MarkdownEditor、模板菜单、资料编辑弹窗
│   ├── composables/      # useApi / useAuth / useToc / useWikiTitle / useThemeSettings
│   ├── utils/            # wiki.ts Markdown 渲染器、format.ts
│   └── assets/css/       # 全局样式（含 .wiki-content）
├── .php/php.ini          # 本地开发 PHP 配置（扩展目录为本机路径）
├── public/               # 静态资源（favicon、.htaccess）
├── build-deploy.sh       # 一键构建并生成部署包
├── nuxt.config.ts        # Nuxt 配置（SPA、/api 开发代理、颜色模式）
└── DEPLOY.md             # 生产部署说明
```

## 快速开始（本地开发）

依赖：**Node.js + pnpm** 与 **PHP ≥ 8.1**（仓库提供 `.php/php.ini` 以启用所需扩展）。

> ⚠️ `.php/php.ini` 中的 `extension_dir` 是**本机** PHP 扩展目录，不同设备可能不同。
> 若启动时提示 `Unable to load dynamic library` 类错误，请先运行 `php -i | grep extension_dir`
> 查看本机扩展目录并修改该配置后再启动。

```bash
# 1. 安装前端依赖
pnpm install

# 2. 启动 PHP API（端口 8765）
#    Nuxt 的 devProxy 会「去掉 /api 前缀」再转发，因此文档根设为 api/（-t api）
php -c .php/php.ini -S 127.0.0.1:8765 -t api

# 3. 启动前端开发服务器
pnpm dev   # 访问 http://localhost:3000
```

4. 首次使用访问 **http://localhost:3000/api/install.php** 完成安装（本地数据库选 **SQLite**）：
   - 安装向导自动创建管理员账号，并从 `api/seed/` 导入两个初始页面（首页、语法帮助）。
   - 若 API 返回 `NOT_INSTALLED`，说明尚未安装，先执行安装向导即可。

> 提示：不要用 `-t api api/index.php` 的方式启动 PHP——把 `api/index.php` 作为内置服务器路由脚本会让**每个请求**都被它拦截，导致 `/install.php` 无法访问而误返回 `NOT_INSTALLED`。

## 初始页面

安装时向导会读取 `api/seed/` 下的两个 Markdown 文件并写入数据库，因此页面内容**独立于 PHP 代码**，如需修改欢迎页 / 语法帮助页的默认内容，直接编辑对应 `.md` 文件即可：

| 文件 | 对应页面 | 说明 |
|------|----------|------|
| `api/seed/welcome.md` | 首页 | 站点名用 `{{SITE_NAME}}` 占位，安装时自动替换为用户填写的站点名 |
| `api/seed/grammar-help.md` | 语法帮助（GrammarHelp） | 完整编辑语法教学 |

## 构建与打包

```bash
# 生成纯静态 SPA 产物 → .output/public
pnpm generate
```

一键构建并生成部署包（推荐）：

```bash
# 生成 deploy/nuxtwiki-<版本>.tar.gz，压缩包内直接是产物（含 index.html、.htaccess、api/ 等）
./build-deploy.sh

# 可用变量覆盖
VERSION=1.2.0 ./build-deploy.sh   # 指定版本号（默认按时间戳）
OUT=dist ./build-deploy.sh        # 指定输出目录（默认 deploy/）
```

> `build-deploy.sh` 在源头排除运行期文件（`api/data`、`api/uploads`、`api/config.php`），
> 这些会在安装时由向导自动创建，运行期数据不会进入产物包。压缩包不嵌套外层目录，解压后即为站点根目录内容。

## 生产部署

详见 [DEPLOY.md](DEPLOY.md)，覆盖 Kangle / Apache / Nginx 及宝塔面板的完整部署步骤：构建打包 → 上传解压 → 运行安装向导 → 配置权限 → 常见问题排查。

## CI 自动化

`.github/workflows/ci.yml` 会在每次 `git push` 时自动执行：

1. **代码检查**：ESLint + TypeScript 类型检查 + PHP 语法检查
2. **构建打包**：复用 `build-deploy.sh` 生成部署包，并作为 **Artifacts** 上传（Actions 运行页 → Artifacts 区域可下载）
3. **发布**：产物同步推送到 `gh-pages` 分支，正式版本也可在 **Releases** 页面获取部署包

## 许可证

[MIT](LICENSE)