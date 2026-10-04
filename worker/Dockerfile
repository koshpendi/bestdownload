# Загрузчик BestDownload: php-fpm + yt-dlp (standalone, обновляется сам) + ffmpeg + deno (нужен yt-dlp для YouTube).
FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg curl ca-certificates unzip \
    && rm -rf /var/lib/apt/lists/*
RUN curl -fsSL https://deno.land/install.sh | DENO_INSTALL=/usr/local sh -s -- -y
RUN curl -fsSL -o /usr/local/bin/yt-dlp https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux \
    && chmod 755 /usr/local/bin/yt-dlp && chown www-data /usr/local/bin/yt-dlp

COPY docker/php.ini /usr/local/etc/php/conf.d/bestdownload.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-bestdownload.conf
COPY --chown=www-data src /app/src
COPY --chown=www-data public /app/public
COPY --chown=www-data cron.php /app/cron.php
COPY plugins /app/plugins
RUN mkdir -p /app/data && chown www-data /app/data
WORKDIR /app
