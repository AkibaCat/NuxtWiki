# NuxtWiki 部署指南

NuxtWiki 採用 **純靜態 SPA 前端（Nuxt 4）+ PHP API** 架構，可部署在 **Kangle（easypanel）/ Apache / Nginx** 等支援 PHP 的虛擬主機上：

- **前端**：Nuxt `ssr: false` 的靜態建置產物，純 HTML/JS/CSS，伺服器**無需 Node 執行環境**。
- **後端**：PHP API（`api/` 目錄，PDO 直接連線資料庫），透過 `index.php?r=<controller>.<action>` 路由。
- **資料庫**：正式環境使用 **MySQL**，本機開發使用 **SQLite**；兩套 schema 均已提供，安裝精靈會自動建表。

> 本文按「先本機打包、後上傳部署」的流程編寫，相容 Kangle / Apache / Nginx / 寶塔（Nginx）等常見環境。

---

## 1. 環境要求

| 元件   | 要求                                                                 |
|--------|----------------------------------------------------------------------|
| 伺服器 | Kangle（easypanel 面板）或其他支援 PHP 的 Web 伺服器                  |
| PHP    | ≥ 8.1，必選擴充：`pdo_mysql`、`mbstring`、`fileinfo`<br>可選擴充：`openssl`、`curl`、`gd`、`zip` |
| MySQL  | 5.7+ / 8.0，字元集 `utf8mb4`（排序規則 `utf8mb4_unicode_ci`）         |
| 可寫目錄 | `api/data/`、`api/uploads/`（框架運行時寫入資料與上傳附件）            |

> 本機開發使用 SQLite（需 `pdo_sqlite`），正式環境使用 MySQL。

---

## 2. 站點目錄結構

將建置產物與 API 放於**同一站點根目錄**（假設部署在網域根路徑 `/` 下）：

```
站點根目錄/
├── index.html          ← SPA 入口（Nuxt 靜態建置產物）
├── 200.html            ← SPA 兜底
├── 404.html
├── _nuxt/              ← 前端 JS/CSS 資源
├── _fonts/             ← 字型資源
├── account/  admin/  pages/  recent/  search/
├── register/  login/  settings/       ← 靜態路由殼（由 nuxt generate 產生）
├── .htaccess           ← 偽靜態（SPA 兜底）規則
└── api/                ← PHP 後端
    ├── index.php       ← API 入口
    ├── install.php     ← 安裝精靈
    ├── bootstrap.php
    ├── schema.mysql.sql / schema.sqlite.sql
    ├── seed/           ← 初始頁面內容（welcome.md / grammar-help.md）
    ├── src/            ← 核心類別與控制器
    ├── config.php      ← 安裝後自動產生（含資料庫連線資訊）
    ├── data/           ← 運行期資料（SQLite / 備份），需可寫
    └── uploads/        ← 附件上傳目錄，需可寫
```

> ⚠️ 前端透過 **根路徑絕對位址** 存取資源（`/api/index.php`、`/_nuxt/...`），因此本站需部署在**網域根路徑**。若需部署在子目錄（如 `example.com/subdir/`），需重新建置並設定 `app.baseURL` 與 `NUXT_PUBLIC_API_BASE`。

---

## 3. 建置打包（在本機開發機執行）

### 3.1 方式一：一鍵腳本（推薦）

儲存庫提供 `build-deploy.sh`，自動完成「安裝依賴 → 建置前端 → 組裝後端 → 打包」：

```bash
./build-deploy.sh
```

- 產物：`deploy/nuxtwiki-<版本>.tar.gz`（預設版本為時間戳，可用 `VERSION=1.2.0` 指定）。
- 壓縮包**不巢套外層目錄**，解壓即為站點根目錄內容。
- 腳本會在源頭排除運行期檔案：`api/data`、`api/uploads`、`api/config.php`（這些會在安裝時自動產生）。

### 3.2 方式二：手動建置

```bash
pnpm install
pnpm generate
```

建置產物位於 `.output/public/`。將其中**所有內容**（含 `index.html`、`200.html`、`404.html`、`.htaccess`、`_nuxt/`、各路由殼）複製到站點根目錄，再將整個 `api/` 目錄複製到 `站點根目錄/api/`（務必保留 `api/seed/`）。

> ⚠️ 必須使用 **`nuxt generate`**（SPA 模式）。它會產生 `index.html`、`200.html`、`404.html` 及各靜態路由殼；`nuxt build`（node-server 預設）不會產生 `index.html`，無法用於純靜態部署。

### 3.3 透過 CI 取得產物

每次 `git push` 後，`.github/workflows/ci.yml` 會自動建置並上傳部署包：

- 儲存庫 → **Actions** → 選擇最新一次執行 → 底部 **Artifacts** → 下載 `nuxtwiki-deploy` 即可。
- 正式版本也可在儲存庫的 **Releases** 頁面取得：Releases 頁 → 對應版本標籤 → **Assets**（原始碼壓縮包與隨附的部署包）。

### 3.4 透過 gh-pages 分支拉取

CI 建置完成後還會把產物**直接推送到 `gh-pages` 分支**（由 `peaceiris/actions-gh-pages` 完成），因此可直接拉取該分支的內容部署，無需在本機建置：

```bash
git fetch origin gh-pages
git checkout -b gh-pages origin/gh-pages   # 首次：基於遠端分支建立本機分支
# 或已有本機分支時：git checkout gh-pages && git pull origin gh-pages
```

- 該分支的根目錄即站點根目錄內容（`index.html`、`api/`、`_nuxt/` 等），拉取後上傳到伺服器站點根目錄即可。
- 由於 `gh-pages` 分支使用 `force_orphan: true` 每次完全重建，**不要**在它上面直接修改再推送，否則會被下一次 CI 覆蓋；對站點內容的長期修改應回到 `main` 分支維護。
- 該分支通常用於「伺服器透過 git 拉取部署」的自動化場景
- 若只是下載部署包，用上文 3.3 的 Artifacts 或從儲存庫 **Releases** 頁面的 Assets 下載更方便。

---

## 4. 上傳與部署

1. 取得部署包 `nuxtwiki-<版本>.tar.gz`（可從儲存庫 **Releases** 頁面的 Assets 下載，或用 3.3 的 CI Artifacts / 本機 `./build-deploy.sh` 產生），然後上傳到伺服器（FTP / SFTP / 面板檔案管理員 / `scp`）。
2. 在伺服器站點根目錄解壓（`tar -xzf nuxtwiki-<版本>.tar.gz`），確保 `index.html`、`api/`、`.htaccess` 直接落在根目錄下。
3. 無需手動匯入 schema，安裝精靈會自動執行建表。
4. 確保 `api/`、`api/data/`、`api/uploads/` 對 PHP 程序**可寫**（見第 5.5 節）。
5. 瀏覽器存取 `http://你的網域/api/install.php` 完成安裝（見第 5 節）。

---

## 5. 正式安裝與設定

### 5.1 設定 PHP 處理（Kangle）

在 easypanel 面板為站點選擇 **PHP 版本（8.1+）**，Kangle 會透過 FastCGI 自動處理 `.php` 請求，無需額外設定。

需確認伺服器 PHP 已啟用 `pdo_mysql`、`mbstring`、`fileinfo` 等擴充（在伺服器 `php.ini` 中取消對應 `extension` 行註解）。

### 5.2 設定偽靜態（SPA 兜底）

在站點根目錄建立 `.htaccess`（Kangle 支援 Apache 風格重寫）：

> 此檔案已在 `public` 目錄與部署包內包含，無需手動建立。

```apache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /

# API 請求交給 PHP 處理（不重寫）
RewriteCond %{REQUEST_URI} !^/api(/|$)

# 已存在的檔案/目錄（_nuxt、_fonts、favicon、uploads 等）不重寫
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# 其餘路徑（/頁面名、/頁面名/edit、/admin 等前端路由）全部交給 SPA 入口
RewriteRule ^(.*)$ /index.html [L]
</IfModule>
```

要點：
- `/api/index.php`、`/api/install.php`、`/api/uploads/...` 都是真實檔案，`RewriteCond %{REQUEST_FILENAME} !-f` 已保證它們不會被重寫；
- `!^/api` 條件用於攔截「不存在的 /api 路徑」，避免被錯誤兜底到 SPA；
- 若面板要求把重寫規則設定在控制面板，將以上 `RewriteCond` / `RewriteRule` 按面板格式填入即可。
- **Nginx** 使用者請將上述規則等價轉換為 `try_files $uri $uri/ /index.html;`（並確保 `/api` 下的請求交給 PHP、不落入 SPA）。

### 5.3 建立 MySQL 資料庫

```sql
CREATE DATABASE nuxtwiki DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nuxtwiki'@'localhost' IDENTIFIED BY '你的強密碼';
GRANT ALL PRIVILEGES ON nuxtwiki.* TO 'nuxtwiki'@'localhost';
FLUSH PRIVILEGES;
```

> 無需手動匯入 schema，安裝精靈會自動執行 `schema.mysql.sql`。

### 5.4 執行安裝精靈

瀏覽器存取 `http://你的網域/api/install.php`：

1. **環境檢查**應全部通過（`PDO_MYSQL` 必須為 ✓，各目錄可寫為 ✓）；
2. 資料庫類型選擇 **MySQL**，填寫主機 / 通訊埠 / 庫名 / 帳號 / 密碼；
3. 填寫站點名稱、首頁頁面名與管理員帳號密碼；
4. 點擊「開始安裝」。

安裝完成後自動產生 `api/config.php`（寫入 MySQL 連線資訊並建表），建立管理員帳號，並從 `api/seed/` 匯入兩個初始頁面（首頁、語法幫助）。最後返回網站首頁。

> 初始頁面內容取自 `api/seed/welcome.md` 與 `api/seed/grammar-help.md`（站名透過 `{{SITE_NAME}}` 佔位符注入），如需自訂預設內容，修改對應 `.md` 後重新安裝即可。

### 5.5 設定目錄權限

確保 Kangle / PHP 程序對以下目錄有**寫**權限：

- `api/data/` —— SQLite / 備份檔案
- `api/uploads/` —— 附件上傳

Linux 可執行 `chown -R <php使用者>:<群組> api/data api/uploads`、`chmod -R u+w api/data api/uploads`；
Windows 可在檔案總管中右鍵目錄 → 內容 → 安全性，授與執行帳號「修改」權限。

### 5.6 驗證測試

- 存取首頁 `http://你的網域/`，應能正常渲染並顯示導覽；
- 存取 `http://你的網域/Home`，應能透過 SPA 兜底正常開啟；
- 用管理員帳號登入，新建頁面、上傳附件；
- 存取 `http://你的網域/api/index.php?r=admin.stats`（未登入應回傳 401/403）；
- 存取 `http://你的網域/api/index.php?r=page.get&tag=HomePage`，應回傳頁面 JSON 資料。

---

## 6. 寶塔面板（Nginx）專屬部署

本小節針對 **寶塔面板 + Nginx + PHP-FPM + MySQL** 的常見環境。若使用 Kangle 或 Apache，請按上文第 5 節操作。

### 6.1 安裝執行環境

在寶塔「軟體商店」安裝：

| 軟體  | 版本要求 | 備註                         |
|-------|----------|------------------------------|
| Nginx | 任一較新版本 | 寶塔預設 Web 伺服器          |
| PHP   | ≥ 8.1     | 8.1 / 8.2 / 8.3 均可           |
| MySQL | 5.7+ / 8.0 | 字元集 `utf8mb4`            |

到「網站 → PHP 設定 → 安裝擴充」勾選 **`pdo_mysql`、`mbstring`、`fileinfo`**（可選 `openssl`、`curl`、`gd`、`zip`）。

### 6.2 新增站點並上傳部署包

1. 本機產生部署包：`./build-deploy.sh`（或透過 Releases / CI Actions 下載）。
2. 寶塔「網站 → 新增站點」，綁定網域，建立站點。
3. 將 `nuxtwiki-<版本>.tar.gz` 上傳到伺服器，並在站點根目錄 `www/wwwroot/<站點名>/` 下解壓，確保 `index.html`、`api/`、`_nuxt/` 直接落在根目錄。
4. 在寶塔「網站 → 設定 → 網站目錄」將**執行目錄**設為站點**根目錄**（`/`）。前端以根路徑絕對位址存取資源，必須承載在根路徑；若需子目錄部署，見上文第 2 節備註。

### 6.3 建立資料庫並執行安裝精靈

1. 寶塔「資料庫」新建資料庫，字元集選 `utf8mb4` → 排序規則 `utf8mb4_unicode_ci`。
2. 存取 `http://你的網域/api/install.php`，資料庫類型選擇 **MySQL**，填寫主機 / 通訊埠 / 庫名 / 帳號 / 密碼，完成安裝。

### 6.4 設定 Nginx 偽靜態（SPA 兜底）

`.htaccess` 僅對 Apache 生效，Nginx 需在寶塔「網站 → 設定 → 偽靜態」中設定。核心是：**真實檔案與 `/api` 下的 PHP 請求不入 SPA 兜底**：

```nginx
location / {
    # 存在的真實檔案/目錄（_nuxt、_fonts、api/uploads 等）直接服務
    if (-e $request_filename) { break; }
    # 其餘前端路由（/頁面名、/頁面名/edit、/admin 等）兜底到 SPA 入口
    rewrite ^(.*)$ /index.html last;
}

# 關鍵：/api 下的 PHP 請求交給 PHP-FPM，避免被上述 rewrite 兜底到 SPA
location ~ ^/api/.+\.php$ {
    include enable-php.conf;
}
```

> 要點：寶塔透過 `include enable-php.conf;` 啟用 PHP-FPM 解析，務必保留；該規則與第 5.2 節 Apache `.htaccess` 的 `RewriteCond !^/api` 邏輯等價。

### 6.5 目錄權限

確保 `api/data/`、`api/uploads/` 對 PHP-FPM 執行使用者（通常為 `www`）可寫：

- 寶塔「檔案」中選取這兩個目錄 → 右鍵「權限」，屬主設為 `www`，並勾選寫權限；
- 或終端機執行：

```bash
chown -R www:www api/data api/uploads
chmod -R u+w api/data api/uploads
```

---

## 7. 常見問題

| 現象 | 排查 |
|------|------|
| 存取 `/頁面名` 404 | 未設定偽靜態兜底，檢查 `.htaccess` 是否正確上傳且 Kangle 已啟用重寫模組 |
| 頁面能開但資料請求失敗 | 檢查 `/api/index.php` 是否可存取；`config.php` 的 MySQL 連線是否正確；`pdo_mysql` 是否啟用 |
| 安裝精靈提示「PDO_MYSQL 缺失」 | 在伺服器 `php.ini` 啟用 `pdo_mysql` 後重啟 Kangle PHP 服務 |
| 附件上傳失敗 | 檢查 `api/uploads/` 寫權限與 `upload_max_filesize` / `post_max_size` 設定 |
| 登入後重新整理失效 | 確認 PHP session 目錄可寫；Cookie 網域與存取網域一致；正式環境建議啟用 HTTPS |
| 頁面能開啟但樣式/圖片異常 | 確認站點的 `_nuxt`、`_fonts`、`api/uploads` 路徑未被偽靜態重寫 |
| 部署包解壓後目錄結構不對 | 確認解壓的是「無外層目錄」的產物包，`index.html`、`api/`、`.htaccess` 應直接位於根目錄 |

---

## 8. 正式環境建議

- **全程啟用 HTTPS**，並確認 `api/config.php` 中 `security` 段 session 設定與站點協定一致。
- 修改 `api/config.php` 中 `mail.from` 與 SMTP 設定，啟用郵件通知。
- 定期在管理後臺「備份」頁匯出資料備份，遷移時可用「匯入」功能還原。
- **不要提交 `api/config.php` 到公開儲存庫**（`.gitignore` 已忽略），其中包含資料庫憑證。

## 9. 更新升級

1. 重新建置並產生最新部署包（`./build-deploy.sh` 或透過 CI Artifacts 下載）。
2. 覆蓋上傳新包內容到站點根目錄（**不要刪除** `api/config.php`、`api/data/`、`api/uploads/`，以保留設定與資料）。
3. 框架會在存取時自動執行冪等的遷移（新增表/欄位），通常無需手動操作。
4. 驗證頁面與歷史資料是否正常。

> 升級不會覆蓋已存在的資料庫內容；若希望更新初始頁面（首頁 / 語法幫助）的預設正文，請直接在站點內編輯對應頁面。