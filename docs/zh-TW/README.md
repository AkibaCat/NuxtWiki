<h1 align="center">NuxtWiki</h1>

<p align="center">
  <img src="../../public/nuxtwiki.svg" alt="NuxtWiki Logo" width="200" height="200" style="display: block;" />
</p>

<p align="center">
  <a href="README.md">English</a> | <a href="../../README.md">简体中文</a> | <a href="../zh-TW/README.md" style="color: #007bff;">繁体中文</a>
</p>

<p align="center">
  <a href="https://nuxt.com/"><img src="https://img.shields.io/badge/Made%20with-Nuxt%204-00DC82?logo=nuxt&labelColor=020420" alt="Made with Nuxt 4"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-%E2%89%A58.1-777BB4?logo=php&labelColor=020420" alt="PHP ≥ 8.1"></a>
  <a href="https://www.mysql.com/"><img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&labelColor=020420" alt="MySQL 5.7+"></a>
</p>

**NuxtWiki** 是一個輕量、快速、開箱即用的個人知識庫 / 團隊 Wiki。它採用「純靜態 SPA 前端 + PHP API」架構：前端由 Nuxt 建置為純靜態檔案，後端使用 PHP 直接連線資料庫，**無需 Node 執行環境**即可部署在任何支援 PHP 的虛擬主機（Kangle / Apache / Nginx 等）上——秒開、穩定、易維護。

---

## 功能特性

- **Markdown 編輯**：標準語法 + 程式碼語法高亮（一鍵複製）、表格、引用、目錄（TOC）、內部連結、圖片與附件；支援 HTML 白名單標籤內嵌 Markdown，還可用 `[{文字|顏色}]` 自訂文字著色
- **頁面編輯器**：沉浸式「編寫 + 即時預覽」介面，所見即所得；側欄可建立工作區頁面並一鍵跳轉編輯
- **版本管理**：每次儲存自動保留修訂記錄，可檢視歷史、比對版本差異、一鍵回滾
- **內容探索**：全文搜尋、最近變更、隨機頁面、反向連結、貢獻者榜
- **存取控制（ACL）**：依頁面為閱讀 / 編輯 / 歷史 / 比對 / 回鏈 / 權限 / 貢獻者設定獨立等級門檻，精細控制誰能看、誰能改
- **使用者體系**：註冊碼開放註冊、登入、個人首頁（頭像 / 簡介 / 社交連結 / 活躍度圖）、凍結與封禁管理
- **訂閱通知**：訂閱關注頁面，更新後第一時間收到通知
- **站點設定**：主題色一鍵切換（科技綠 / 天空藍 / 活力橙 / 憂鬱紫 / 中國紅 / 檸檬黃 / 櫻花粉 / 珊瑚粉 / 酒紅色 / 楓葉橙 / 薑黃色 / 薄荷綠 / 墨綠色 / 抹茶綠 / 藏青色 / 霧霾藍 / 克萊因藍 / 蒂芙尼藍 / 香芋紫 / 高級灰 / 奶茶色 / 咖啡棕 / 曜石黑 / 象牙白），淺色 / 深色模式
- **管理後臺**：站點統計、站點設定、頁面 / 使用者 / 註冊碼管理、資料備份與匯入

## 技術棧

| 層   | 技術 |
|------|------|
| 前端 | Nuxt 4 · Nuxt UI v4 · Tailwind CSS v4 · Vue 3 |
| 後端 | PHP ≥ 8.1（擴充：`pdo_mysql`、`mbstring`、`fileinfo`；可選 `openssl` / `curl` / `gd` / `zip`） |
| 資料庫 | MySQL 5.7+ / 8.0（正式環境）· SQLite（本機開發） |

## 架構概覽

```
瀏覽器
  │  https://你的網域/          靜態 SPA（index.html + _nuxt + _fonts）
  │  https://你的網域/api/...    PHP 後端（index.php?r=controller.action）
  ▼
┌────────────────┐       ┌──────────────────────────────────┐
│  靜態資源伺服器 │       │               PHP 後端            │
│  Nginx/Apache  │       │  index.php → Router → Controller │
│  / Kangle      │       │  PDO 直接連線資料庫               │
└────────────────┘       └──────────┬───────────────┬───────┘
                                    │               │
                              MySQL（正式環境）      SQLite（本機）
```

前端透過根路徑絕對位址存取資源（`/api/index.php`、`/_nuxt/...`），因此站點需部署在**網域根路徑**；如需部署到子目錄，請重新建置並設定 `app.baseURL` 與 `NUXT_PUBLIC_API_BASE`。

## 專案結構

```
├── api/                  # PHP 後端
│   ├── index.php         # API 入口（index.php?r=<controller>.<action>）
│   ├── install.php       # 安裝精靈（環境檢查 + 建庫 + 建立管理員 + 初始頁面）
│   ├── bootstrap.php     # 啟動引導（載入設定與核心類別）
│   ├── schema.mysql.sql  # MySQL 表結構
│   ├── schema.sqlite.sql # SQLite 表結構
│   ├── seed/             # 安裝時的初始頁面內容（獨立 Markdown 檔案）
│   │   ├── welcome.md        # 歡迎頁（首頁），{{SITE_NAME}} 由精靈注入站點名
│   │   └── grammar-help.md   # 語法幫助頁
│   ├── config.php        # 設定（安裝時產生，已被 .gitignore 忽略）
│   └── src/              # Router / Auth / Database / Settings / Migrate / Controllers
├── app/                  # Nuxt 前端
│   ├── pages/            # 頁面路由（[tag]/ 含編輯/歷史/比對/ACL/回鏈/貢獻者、搜尋、設定…）
│   ├── components/       # WikiView 渲染器、MarkdownEditor、模板選單、資料編輯彈窗
│   ├── composables/      # useApi / useAuth / useToc / useWikiTitle / useThemeSettings
│   ├── utils/            # wiki.ts Markdown 渲染器、format.ts
│   └── assets/css/       # 全域樣式（含 .wiki-content）
├── .php/php.ini          # 本機開發 PHP 設定（擴充目錄為本機路徑）
├── public/               # 靜態資源（favicon、.htaccess）
├── build-deploy.sh       # 一鍵建置並產生部署包
├── nuxt.config.ts        # Nuxt 設定（SPA、/api 開發代理、顏色模式）
└── DEPLOY.md             # 正式部署說明
```

## 快速開始（本機開發）

依賴：**Node.js + pnpm** 與 **PHP ≥ 8.1**（儲存庫提供 `.php/php.ini` 以啟用所需擴充）。

> ⚠️ `.php/php.ini` 中的 `extension_dir` 是**本機** PHP 擴充目錄，不同裝置可能不同。
> 若啟動時提示 `Unable to load dynamic library` 類錯誤，請先執行 `php -i | grep extension_dir`
> 檢視本機擴充目錄並修改該設定後再啟動。

```bash
# 1. 安裝前端依賴
pnpm install

# 2. 啟動 PHP API（通訊埠 8765）
#    Nuxt 的 devProxy 會「去掉 /api 前綴」再轉發，因此文件根設為 api/（-t api）
php -c .php/php.ini -S 127.0.0.1:8765 -t api

# 3. 啟動前端開發伺服器
pnpm dev   # 存取 http://localhost:3000
```

4. 首次使用存取 **http://localhost:3000/api/install.php** 完成安裝（本機資料庫選 **SQLite**）：
   - 安裝精靈自動建立管理員帳號，並從 `api/seed/` 匯入兩個初始頁面（首頁、語法幫助）。
   - 若 API 回傳 `NOT_INSTALLED`，表示尚未安裝，先執行安裝精靈即可。

> 提示：不要用 `-t api api/index.php` 的方式啟動 PHP——把 `api/index.php` 作為內建伺服器路由腳本會讓**每個請求**都被它攔截，導致 `/install.php` 無法存取而誤回傳 `NOT_INSTALLED`。

## 初始頁面

安裝時精靈會讀取 `api/seed/` 下的兩個 Markdown 檔案並寫入資料庫，因此頁面內容**獨立於 PHP 程式碼**，如需修改歡迎頁 / 語法幫助頁的預設內容，直接編輯對應 `.md` 檔案即可：

| 檔案 | 對應頁面 | 說明 |
|------|----------|------|
| `api/seed/welcome.md` | 首頁 | 站點名用 `{{SITE_NAME}}` 佔位，安裝時自動替換為使用者填寫的站點名 |
| `api/seed/grammar-help.md` | 語法幫助（GrammarHelp） | 完整編輯語法教學 |

## 建置與打包

```bash
# 產生純靜態 SPA 產物 → .output/public
pnpm generate
```

一鍵建置並產生部署包（推薦）：

```bash
# 產生 deploy/nuxtwiki-<版本>.tar.gz，壓縮包內直接是產物（含 index.html、.htaccess、api/ 等）
./build-deploy.sh

# 可用變數覆蓋
VERSION=1.2.0 ./build-deploy.sh   # 指定版本號（預設按時間戳）
OUT=dist ./build-deploy.sh        # 指定輸出目錄（預設 deploy/）
```

> `build-deploy.sh` 在源頭排除運行期檔案（`api/data`、`api/uploads`、`api/config.php`），
> 這些會在安裝時由精靈自動建立，運行期資料不會進入產物包。壓縮包不巢套外層目錄，解壓後即為站點根目錄內容。

## 正式部署

詳見 [DEPLOY.md](DEPLOY.md)，涵蓋 Kangle / Apache / Nginx 及寶塔面板的完整部署步驟：建置打包 → 上傳解壓 → 執行安裝精靈 → 設定權限 → 常見問題排查。

## CI 自動化

`.github/workflows/ci.yml` 會在每次 `git push` 時自動執行：

1. **程式碼檢查**：ESLint + TypeScript 型別檢查 + PHP 語法檢查
2. **建置打包**：重用 `build-deploy.sh` 產生部署包，並作為 **Artifacts** 上傳（Actions 執行頁 → Artifacts 區域可下載）
3. **發佈**：產物同步推送到 `gh-pages` 分支，正式版本也可在 **Releases** 頁面取得部署包

## 授權條款

[MIT](LICENSE)