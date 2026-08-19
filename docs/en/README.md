<h1 align="center">NuxtWiki</h1>

<p align="center">
  <a href="README.md" style="color: #007bff;">English</a> | <a href="../../README.md">简体中文</a> | <a href="../zh-TW/README.md">繁体中文</a>
</p>

<p align="center">
  <a href="https://nuxt.com/"><img src="https://img.shields.io/badge/Made%20with-Nuxt%204-00DC82?logo=nuxt&labelColor=020420" alt="Made with Nuxt 4"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-%E2%89%A58.1-777BB4?logo=php&labelColor=020420" alt="PHP ≥ 8.1"></a>
  <a href="https://www.mysql.com/"><img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&labelColor=020420" alt="MySQL 5.7+"></a>
</p>

**NuxtWiki** is a lightweight, fast, and out-of-the-box personal knowledge base / team Wiki. It adopts a **pure static SPA frontend + PHP API** architecture: the frontend is built by Nuxt into pure static files, while the backend uses PHP to connect directly to the database. It can be deployed on **any PHP-capable virtual host (Kangle / Apache / Nginx, etc.) without a Node runtime** — instant loading, stable, and easy to maintain.

---

## Features

- **Markdown editing**: standard syntax + code syntax highlighting (copy with one click), tables, blockquotes, table of contents (TOC), internal links, images & attachments; supports embedding Markdown within an allowlisted set of HTML tags, and custom text coloring using `[{text|color}`
- **Page editor**: an immersive "write + live preview" interface that is WYSIWYG; the sidebar can create workspace pages and jump to editing with one click
- **Version control**: every save automatically keeps a revision history, with the ability to view history, diff between versions, and revert with one click
- **Content discovery**: full-text search, recent changes, random page, backlinks, contributor leaderboard
- **Access control (ACL)**: sets independent permission thresholds for read / edit / history / diff / backlink / permission / contributor on a per-page basis, giving fine-grained control over who can view and who can edit
- **User system**: open registration via registration codes, login, personal profile (avatar / bio / social links / activity graph), freeze and ban management
- **Subscription notifications**: subscribe to followed pages and get notified right away when they are updated
- **Site settings**: one-click theme color switching (Tech Green / Sky Blue / Vibrant Orange / Melancholy Purple / Chinese Red / Lemon Yellow / Sakura Pink / Coral Pink / Wine Red / Maple Orange / Ginger Yellow / Mint Green / Dark Green / Matcha Green / Navy Blue / Haze Blue / Klein Blue / Tiffany Blue / Taro Purple / Premium Gray / Milk Tea / Coffee Brown / Obsidian Black / Ivory White), light / dark mode
- **Admin panel**: site statistics, site settings, page / user / registration-code management, data backup and import

## Tech Stack

| Layer    | Technology |
|----------|------------|
| Frontend | Nuxt 4 · Nuxt UI v4 · Tailwind CSS v4 · Vue 3 |
| Backend  | PHP ≥ 8.1 (extensions: `pdo_mysql`, `mbstring`, `fileinfo`; optional `openssl` / `curl` / `gd` / `zip`) |
| Database | MySQL 5.7+ / 8.0 (production) · SQLite (local development) |

## Architecture Overview

```
Browser
  │  https://your-domain/        Static SPA (index.html + _nuxt + _fonts)
  │  https://your-domain/api/... PHP backend (index.php?r=controller.action)
  ▼
┌──────────────────┐       ┌──────────────────────────────────┐
│ Static asset     │       │             PHP backend          │
│ server           │       │  index.php → Router → Controller │
│ Nginx/Apache     │       │  PDO direct DB access            │
│ / Kangle         │       │                                  │
└──────────────────┘       └──────────┬───────────────┬───────┘
                                      │               │
                                MySQL (prod)     SQLite (local)
```

The frontend accesses resources via root-path absolute URLs (`/api/index.php`, `/_nuxt/...`), so the site must be deployed at the **domain root path**. If you need to deploy into a subdirectory, rebuild and configure `app.baseURL` and `NUXT_PUBLIC_API_BASE`.

## Project Structure

```
├── api/                  # PHP backend
│   ├── index.php         # API entry point (index.php?r=<controller>.<action>)
│   ├── install.php       # Installation wizard (environment check + create DB + create admin + initial pages)
│   ├── bootstrap.php     # Bootstrap (loads config and core classes)
│   ├── schema.mysql.sql  # MySQL table schema
│   ├── schema.sqlite.sql # SQLite table schema
│   ├── seed/             # Initial page content for install (standalone Markdown files)
│   │   ├── welcome.md        # Welcome page (homepage), {{SITE_NAME}} is injected with the site name by the wizard
│   │   └── grammar-help.md   # Grammar help page
│   ├── config.php        # Config (generated at install time, ignored via .gitignore)
│   └── src/              # Router / Auth / Database / Settings / Migrate / Controllers
├── app/                  # Nuxt frontend
│   ├── pages/            # Page routes ([tag]/ incl. edit/history/diff/ACL/backlink/contributor, search, settings…)
│   ├── components/       # WikiView renderer, MarkdownEditor, template menu, profile edit modal
│   ├── composables/      # useApi / useAuth / useToc / useWikiTitle / useThemeSettings
│   ├── utils/            # wiki.ts Markdown renderer, format.ts
│   └── assets/css/       # Global styles (incl. .wiki-content)
├── .php/php.ini          # Local dev PHP config (extension dir is a local path)
├── public/               # Static assets (favicon, .htaccess)
├── build-deploy.sh       # One-click build & deployment package generator
├── nuxt.config.ts        # Nuxt config (SPA, /api dev proxy, color mode)
└── DEPLOY.md             # Production deployment guide
```

## Quick Start (Local Development)

Dependencies: **Node.js + pnpm** and **PHP ≥ 8.1** (the repo ships `.php/php.ini` to enable the required extensions).

> ⚠️ The `extension_dir` in `.php/php.ini` points to a **local** PHP extension directory, which may differ between machines.
> If you get errors like `Unable to load dynamic library` at startup, first run `php -i | grep extension_dir`
> to inspect your local extension directory and update that config before starting.

```bash
# 1. Install frontend dependencies
pnpm install

# 2. Start the PHP API (port 8765)
#    Nuxt's devProxy strips the "/api" prefix before forwarding, so the docroot is set to api/ (-t api)
php -c .php/php.ini -S 127.0.0.1:8765 -t api

# 3. Start the frontend dev server
pnpm dev   # visit http://localhost:3000
```

4. On first use, visit **http://localhost:3000/api/install.php** to complete the installation (choose **SQLite** for the local database):
   - The installation wizard automatically creates the admin account and imports the two initial pages (homepage, grammar help) from `api/seed/`.
   - If the API returns `NOT_INSTALLED`, it means the site is not yet installed — just run the installation wizard first.

> Tip: do not start PHP with `-t api api/index.php` — making `api/index.php` the built-in server router script causes **every request** to be intercepted by it, so `/install.php` becomes inaccessible and wrongly returns `NOT_INSTALLED`.

## Initial Pages

At install time the wizard reads the two Markdown files under `api/seed/` and writes them into the database, so the page content is **independent of the PHP code**. To modify the default content of the welcome / grammar help pages, simply edit the corresponding `.md` file:

| File                          | Corresponding page             | Description |
|-------------------------------|--------------------------------|-------------|
| `api/seed/welcome.md`         | Homepage                       | The site name uses the `{{SITE_NAME}}` placeholder and is auto-replaced with the name the user fills in at install time |
| `api/seed/grammar-help.md`    | Grammar help (GrammarHelp)     | Complete editing grammar tutorial |

## Build & Package

```bash
# Generate the pure static SPA build → .output/public
pnpm generate
```

One-click build and deployment package generation (recommended):

```bash
# Generates deploy/nuxtwiki-<version>.tar.gz; the archive contains the build output directly (incl. index.html, .htaccess, api/, etc.)
./build-deploy.sh

# Overridable variables
VERSION=1.2.0 ./build-deploy.sh   # specify a version number (defaults to a timestamp)
OUT=dist ./build-deploy.sh        # specify the output directory (defaults to deploy/)
```

> `build-deploy.sh` excludes runtime files at the source (`api/data`, `api/uploads`, `api/config.php`);
> these are auto-created by the wizard at install time, so runtime data never enters the package. The archive does not nest an outer directory — after extraction its contents are directly the site root directory.

## Production Deployment

See [DEPLOY.md](DEPLOY.md) for the complete deployment steps covering Kangle / Apache / Nginx and the BT Panel (BaoTa): build & package → upload & extract → run the installation wizard → configure permissions → troubleshooting.

## CI Automation

`.github/workflows/ci.yml` runs automatically on every `git push`:

1. **Code checks**: ESLint + TypeScript type checking + PHP syntax checking
2. **Build & package**: reuses `build-deploy.sh` to generate the deployment package and uploads it as **Artifacts** (downloadable from the Actions run page → Artifacts section)
3. **Release**: the build output is also pushed to the `gh-pages` branch, and release builds can be obtained from the **Releases** page

## License

[MIT](LICENSE)