<?php

declare(strict_types=1);

/** Задачи скачивания: учёт активных загрузок и готовых файлов. */
final class Jobs
{
    private const STALE = 30; // задача без пульса дольше этого считается мёртвой

    public static function create(): string
    {
        $db = db();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $alive = time() - self::STALE;
            $st = $db->prepare("SELECT COUNT(*) FROM jobs WHERE status = 'running' AND heartbeat > ? AND ip = ?");
            $st->execute([$alive, client_ip()]);
            if ($st->fetchColumn() > 0) throw new UserError('Дождитесь окончания текущей загрузки.', 429);
            $st = $db->prepare("SELECT COUNT(*) FROM jobs WHERE status = 'running' AND heartbeat > ?");
            $st->execute([$alive]);
            if ($st->fetchColumn() >= (int) cfg('MAX_ACTIVE')) {
                throw new UserError('Сервер занят. Попробуйте через минуту.', 503);
            }
            $id = bin2hex(random_bytes(12));
            $db->prepare("INSERT INTO jobs (id, ip, status, created, heartbeat) VALUES (?, ?, 'running', ?, ?)")
                ->execute([$id, client_ip(), time(), time()]);
            $db->exec('COMMIT');
            return $id;
        } catch (Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }

    public static function beat(string $id): void
    {
        db()->prepare('UPDATE jobs SET heartbeat = ? WHERE id = ?')->execute([time(), $id]);
    }

    public static function finish(string $id, string $status, ?string $file = null, ?string $name = null): void
    {
        db()->prepare('UPDATE jobs SET status = ?, file = ?, name = ?, heartbeat = ? WHERE id = ?')
            ->execute([$status, $file, $name, time(), $id]);
    }

    public static function get(string $id): ?array
    {
        $st = db()->prepare('SELECT * FROM jobs WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function dir(string $id): string
    {
        return data_dir('files') . '/' . $id;
    }

    /** Удаляет папки задач старше FILE_TTL. Это только файлы, созданные самим сервисом. */
    public static function cleanup(): int
    {
        $n = 0;
        $st = db()->prepare("SELECT id FROM jobs WHERE created < ?");
        $st->execute([time() - (int) cfg('FILE_TTL')]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!preg_match('~^[0-9a-f]{24}$~', $id)) continue;
            $dir = self::dir($id);
            foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
            @rmdir($dir);
            db()->prepare('DELETE FROM jobs WHERE id = ?')->execute([$id]);
            $n++;
        }
        $ttl = (int) cfg('INFO_TTL');
        db()->prepare('DELETE FROM info_cache WHERE ts < ?')->execute([time() - $ttl]);
        foreach (glob(data_dir('info') . '/*.json') ?: [] as $f) if (time() - filemtime($f) > $ttl) @unlink($f);
        RateLimiter::purge();
        return $n;
    }
}
