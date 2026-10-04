<?php

declare(strict_types=1);

/**
 * Бережное обращение с YouTube: интервал между запросами со всего сервиса и пауза после блока.
 * Пока действует блок, к YouTube не ходим вовсе — повторные запросы только продлевают его.
 */
final class YoutubeGuard
{
    public static function applies(string $url): bool
    {
        return str_starts_with($url, 'https://www.youtube.com/');
    }

    /** Вызывать перед каждым запуском yt-dlp для YouTube. */
    public static function before(): void
    {
        $until = (int) meta_get('yt_block_until');
        if ($until > time()) {
            $min = max(1, (int) ceil(($until - time()) / 60));
            throw new UserError("YouTube временно ограничил сервер. Попробуйте через $min мин.", 503);
        }
        // Слот выдаём под блокировкой БД, чтобы параллельные запросы не пошли одной пачкой.
        $gap = (float) cfg('YT_MIN_GAP');
        $db = db();
        $db->exec('BEGIN IMMEDIATE');
        $next = max(microtime(true), (float) meta_get('yt_next_slot'));
        meta_set('yt_next_slot', (string) ($next + $gap));
        $db->exec('COMMIT');
        $wait = $next - microtime(true);
        if ($wait > 15) throw new UserError('Сервер занят. Попробуйте через минуту.', 503);
        if ($wait > 0) usleep((int) ($wait * 1e6));
    }

    /** Вызывать с stderr неудачного запуска: если это блок — включаем паузу. */
    public static function after(string $err): void
    {
        if (self::isBlock($err)) {
            meta_set('yt_block_until', (string) (time() + (int) cfg('YT_BACKOFF')));
            error_log('YouTube block, backoff ' . cfg('YT_BACKOFF') . 's');
        }
    }

    public static function isBlock(string $err): bool
    {
        return (bool) preg_match('~confirm you.re not a bot|HTTP Error 429|Too Many Requests~iu', $err);
    }
}
