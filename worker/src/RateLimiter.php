<?php

declare(strict_types=1);

/** Лимиты по IP на скользящем окне в час. */
final class RateLimiter
{
    public static function hit(string $kind, int $limit, int $window = 3600): void
    {
        $ip = client_ip();
        $now = time();
        $db = db();
        $st = $db->prepare('SELECT COUNT(*) FROM hits WHERE ip = ? AND kind = ? AND ts > ?');
        $st->execute([$ip, $kind, $now - $window]);
        if ((int) $st->fetchColumn() >= $limit) {
            throw new UserError('Слишком много запросов. Попробуйте через час.', 429);
        }
        $db->prepare('INSERT INTO hits (ip, kind, ts) VALUES (?, ?, ?)')->execute([$ip, $kind, $now]);
    }

    public static function purge(): void
    {
        db()->prepare('DELETE FROM hits WHERE ts < ?')->execute([time() - 86400]);
    }
}
