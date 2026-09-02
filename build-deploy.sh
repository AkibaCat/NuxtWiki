#!/usr/bin/env bash
#
# build-deploy.sh — 构建 NuxtWiki 并生成可部署的产物包
#
# 用法：
#   ./build-deploy.sh                 # 构建并打包，输出到 ./deploy/nuxtwiki-<VERSION>.tar.gz
#   VERSION=1.2.0 ./build-deploy.sh   # 指定版本号
#   OUT=dist ./build-deploy.sh        # 指定打包输出目录
#   TARGET=deploy/publish NAME=build-x ./build-deploy.sh  # 覆盖产物目录与压缩包名（CI 用）
#
# 依赖：Node.js + pnpm、可选的 tar（Git Bash / WSL / Linux / macOS 均可）。
#
# 产物包结构（对应生产站点根目录，见 DEPLOY.md）：
#   index.html / 200.html / 404.html / .htaccess
#   _nuxt/ _fonts/ account/ admin/ ...  前端静态 SPA 产物
#   api/                                 PHP 后端（不含运行期生成物）

set -euo pipefail

# ---------- 可覆盖的变量 ----------
VERSION="v1.3.3"
TIME="${TIME:-$(date +%Y%m%d%H%M)}"
OUT_DIR="${OUT:-deploy}"
# 以下三个变量均支持环境变量覆盖（如 CI：export NAME=build-<sha> TARGET=deploy/publish）
NAME="${NAME:-NuxtWiki-${VERSION}-build-${TIME}}"
TARGET="${TARGET:-${OUT_DIR}/${NAME}}"
TARBALL="${OUT_DIR}/${NAME}.tar.gz"

# ---------- 前置检查 ----------
command -v pnpm >/dev/null 2>&1 || { echo "[错误] 未找到 pnpm，请先安装 Node.js 与 pnpm" >&2; exit 1; }
cd "$(dirname "$0")"

echo "==> 1/5 安装依赖并构建前端 (nuxt generate)"
pnpm install --frozen-lockfile
pnpm generate

if [ ! -d ".output/public" ]; then
  echo "[错误] 构建失败：缺少 .output/public" >&2
  exit 1
fi

echo "==> 2/5 准备部署目录 ${TARGET}"
rm -rf "${TARGET}" "${TARBALL}"
mkdir -p "${TARGET}"

echo "==> 3/5 复制前端产物"
cp -a .output/public/. "${TARGET}/"
test -f "${TARGET}/index.html" || { echo "[错误] 前端产物缺少 index.html（请确认使用 nuxt generate 而非 nuxt build）" >&2; exit 1; }

echo "==> 4/5 复制 PHP 后端（排除运行期/开发期文件）"
mkdir -p "${TARGET}/api"
# 用 tar 流式复制，--exclude 在源头就把运行期/开发期文件剔除，
# 因此 api/data、api/uploads、config.php 等永远不会进入产物包。
tar -C api \
  --exclude='./data' \
  --exclude='./uploads' \
  --exclude='./config.php' \
  -cf - . | tar -xf - -C "${TARGET}/api"
# 校验：确认运行期目录未被带入产物包
if (cd "${TARGET}/api" && [ -d data ]) || (cd "${TARGET}/api" && [ -d uploads ]); then
  echo "[错误] api/data 或 api/uploads 不应出现在产物包中" >&2
  exit 1
fi

echo "==> 5/5 打包 ${TARBALL}"
tar -czf "${TARBALL}" -C "${TARGET}" .

echo ""
echo "[完成] 产物包已生成：${TARBALL}"
echo "[说明] 解压该包并把内容放到站点根目录，浏览器访问 /api/install.php 完成部署。"