#!/bin/sh
# Останавливает локальный BestDownload: страница :8090, загрузчик :8092, PO-токены :4416.
for port in 8090 8092 4416; do
  pids=$(lsof -tiTCP:$port -sTCP:LISTEN 2>/dev/null) || true
  [ -n "$pids" ] && kill $pids 2>/dev/null || true
done
echo "BestDownload остановлен."
