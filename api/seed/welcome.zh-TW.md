[![Nuxt v4](https://img.shields.io/badge/Made%20with-Nuxt%20v4-00DC82?logo=nuxt&labelColor=020420)](https://nuxt.com)

歡迎來到 **{{SITE_NAME}}** —— 一個輕量、快速、開箱即用的個人知識庫 / 團隊 Wiki。

> 本頁面由安裝精靈自動產生，你可以隨時編輯，把它改造成你的網站首頁。

## 關於本站

{{SITE_NAME}} 採用「純靜態前端 + PHP API」架構：前端由 Nuxt 建置為純靜態頁面，後端使用 PHP 直連資料庫（PDO），無需 Node 執行環境即可部署在 Kangle / Apache / Nginx 等任意虛擬主機上，秒開、穩定、易於維護，幾乎零管理成本。

## 核心功能

<details>
<summary>Markdown 編輯</summary>

- 標準語法：標題、加粗、斜體、清單、表格、引用、程式碼區塊、分隔線
- 程式碼語法高亮與一鍵複製，讓技術文件閱讀更順手
- 自訂文字著色：使用 `[{文字|顏色}]` 為文字上色，支援 6 位十六進位（如 `3c9c5c`）、RGB（如 `60:156:92`）以及主題色令牌 `$TC`（如 `[{NuxtWiki|$TC}]` 會以網站的主題色呈現）
- 支援 **HTML 白名單標籤巢狀**：在 `div`、`details`、`table`、`span`、`mark` 等標籤內部可以繼續撰寫 Markdown，例如下面這個 `div` 容器：

<div class='wiki-note' style='border:1px solid #e5e7eb;border-radius:8px;padding:8px 16px;background:#f6f8fa'>

> 這是放在 `div` 容器裡的一段引用，內部的 **Markdown 依然有效**。

- 項目一
- 項目二

</div>
</details>

<details>
<summary>沉浸式網頁編輯器</summary>

- 沉浸式全螢幕編輯介面，去除干擾，專注寫作
- 邊寫邊看即時預覽，所見即所得
- 支援多種編輯快速操作，讓排版更有效率

</details>

<details>
<summary>版本管理</summary>

- 每次儲存自動保留修訂紀錄
- 隨時檢視歷史、比對差異、一鍵還原到任何版本

</details>

<details>
<summary>探索與檢索</summary>

- 全文搜尋、最近變更、隨機頁面
- 反向連結、貢獻者榜，輕鬆探索相關內容

</details>

<details>
<summary>存取控制（ACL）</summary>

每個頁面可分別設定閱讀、編輯、歷史、差異、反向連結、權限與貢獻者的等級門檻，精細控管誰可以看、誰可以改。

</details>

<details>
<summary>訂閱通知</summary>

訂閱關注的頁面，更新後第一時間收到通知，重要內容不再錯過。

</details>

<details>
<summary>使用者體系</summary>

- 透過註冊碼開放註冊，控管成員准入
- 個人主頁支援大頭照、簡介、社交連結與活躍度圖
- 支援凍結與停權，彈性管理成員

</details>

<details>
<summary>主題 / 佈景</summary>

- 提供 24 套預設主題色，可自由切換
- 支援淺色 / 深色模式，自動適配使用環境
- 介面語言可選 简体中文 / 繁體中文 / English

</details>

<details>
<summary>管理後臺</summary>

網站統計、網站設定、頁面 / 使用者 / 註冊碼管理，以及資料備份與匯入，一站掌握全站。

</details>

## 快速開始

1. 點擊右上角「登入」，使用安裝時設定的管理員帳號登入
2. 點擊「建立頁面」或右上角「新增」，寫下你的第一篇文件
3. 對照 [[語法幫助|GrammarHelp]] 學習完整的編輯語法
4. 在頁面詳情頁點擊「訂閱」，持續關注頁面更新

## 相關頁面

- [[語法幫助|GrammarHelp]] —— 完整的 Markdown 與 HTML 巢狀語法教學

> 提示：本頁內容可隨時編輯，從這裡開始你的 Wiki 之旅吧！