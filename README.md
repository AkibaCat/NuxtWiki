# NuxtWiki

<p align="center">
  <a href="https://nuxt.com/"><img src="https://img.shields.io/badge/Made%20with-Nuxt%20v4-00DC82?logo=nuxt&labelColor=020420" alt="Nuxt v4"></a>
  <a href="https://ui.nuxt.com/"><img src="https://img.shields.io/badge/Made%20with-Nuxt%20UI-00DC82?logo=nuxt&labelColor=020420" alt="Nuxt UI"></a>
</p>


一个轻量、快速、开箱即用的个人知识库 / 团队 Wiki。

- **前端**：Nuxt 4 + Nuxt UI（`ssr: false` 的 SPA），构建为纯静态文件，无需 Node 运行时即可部署。
- **后端**：PHP API（PDO 直连 MySQL / SQLite），通过 `index.php?r=<controller>.<action>` 路由。
- **部署**：兼容 Kangle（easypanel）/ Apache / Nginx 等任意支持 PHP 的虚拟主机。

## 功能特性

- **Markdown 编辑**：标准 Markdown 语法，支持代码块（自动高亮 + 一键复制）、表格、引用、目录（TOC）、内部链接、图片与附件
- **版本管理**：每次保存自动保留修订记录，可查看历史、对比版本差异并回滚
- **发现内容**：全文搜索、最近更改、随机页面、反向链接、贡献者榜
- **访问控制（ACL）**：按页面分别设置阅读 / 编辑 / 历史 / 对比 / 回链 / 权限 / 贡献者 的等级门槛
- **用户系统**：注册码注册、登录、个人主页（头像 / 简介 / 社交链接 / 活跃度图）、冻结 / 封禁管理
- **订阅通知**：订阅关注的页面，更新后收到通知
- **管理后台**：站点统计、站点设置、页面管理、用户管理、注册码管理、数据备份与导入
- **体验**：响应式布局，浅色 / 深色主题，RSS 订阅（管理员可见）

## 技术栈

| 层 | 技术 |
|----|------|
| 前端 | Nuxt 4 · Nuxt UI v4 · Tailwind CSS v4 · Vue 3 |
| 后端 | PHP ≥ 8.1（`pdo_mysql`、`pdo_sqlite`、`mbstring`、`fileinfo`） |
| 数据库 | MySQL 5.7+ / 8.0（生产）· SQLite（本地开发） |

## 项目结构

```
├── api/                  # PHP 后端
│   ├── index.php         # API 入口（index.php?r=<controller>.<action>）
│   ├── install.php       # 安装向导（环境检查 + 建库 + 创建管理员 + 初始页面）
│   ├── bootstrap.php     # 启动引导（加载配置与核心类）
│   ├── schema.mysql.sql  # MySQL 表结构
│   ├── schema.sqlite.sql # SQLite 表结构
│   ├── config.php        # 配置（安装时生成，已被 .gitignore 忽略）
│   └── src/              # Router / Auth / Database / Settings / Migrate / Controllers ...
├── app/                  # Nuxt 前端
│   ├── pages/            # 页面路由（[tag]/ 含编辑/历史/对比/ACL/回链/贡献者）
│   ├── components/       # WikiView 渲染器、个人资料编辑弹窗、模板菜单
│   ├── composables/      # useApi / useAuth
│   ├── utils/            # wiki.ts Markdown 渲染器、format.ts
│   └── assets/css/       # 全局样式（含 .wiki-content）
├── public/               # 静态资源（favicon、.htaccess）
├── _local_test/          # 本地测试目录（构建产物 + PHP API + router.php，不入库）
├── nuxt.config.ts        # Nuxt 配置（SPA、/api 开发代理）
└── DEPLOY.md             # 生产部署说明
```

## 本地开发

依赖：Node.js（pnpm）与 PHP ≥ 8.1（本仓库提供 `.php/php.ini` 以启用所需扩展）。

```bash
# 1. 安装前端依赖
pnpm install

# 2. 启动 PHP API（端口 8765，Nuxt dev 已通过 /api 代理转发）
php -c .php/php.ini -S 127.0.0.1:8765 -t api api/index.php

# 3. 启动前端开发服务器 http://localhost:3000
pnpm dev
```

首次使用访问 `http://localhost:3000/api/install.php` 完成安装（本地数据库选 SQLite），
安装向导会自动创建管理员账号与两个初始页面（首页、语法帮助）。

> 若首次访问 API 返回 `NOT_INSTALLED`，说明尚未安装，请先执行安装向导。

## 构建与本地测试

```bash
# 生成纯静态 SPA 产物 -> .output/public
pnpm generate
```

将 `.output/public` 与 `api/` 同步到 `_local_test` 后，可用内置路由器模拟线上环境：

```bash
php -c .php/php.ini -S 127.0.0.1:8090 -t _local_test _local_test/router.php
```

访问 `http://127.0.0.1:8090` 即可按生产方式（SPA 回退 + PHP API）联调。

> 注意：请使用 `nuxt generate` 而非 `nuxt build`——后者（node-server 预设）不会在
> `public` 目录生成 `index.html`，无法用于纯静态部署。

## 部署

详见 [DEPLOY.md](DEPLOY.md)，覆盖 Kangle + MySQL + PHP 的完整部署步骤。

## 许可证

MIT
