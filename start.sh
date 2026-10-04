#!/bin/sh
# Локальный запуск: страница на :8090, загрузчик на :8092. Нужны php, yt-dlp, ffmpeg, deno (brew).
cd "$(dirname "$0")"
# Генератор PO-токенов YouTube: vendor/bgutil-ytdlp-pot-provider (git clone ветки 2.0.0, npm ci, npx tsc).
(cd vendor/bgutil-ytdlp-pot-provider/server && node build/main.js > ../../../pot-server.log 2>&1) &
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8092 -t worker/public worker/public/index.php > worker/data-server.log 2>&1 &
php -S 127.0.0.1:8090 -t site > site-server.log 2>&1 &
echo "Токены: http://127.0.0.1:4416/ping  ·  Страница: http://127.0.0.1:8090  ·  Загрузчик: http://127.0.0.1:8092/health"
wait
