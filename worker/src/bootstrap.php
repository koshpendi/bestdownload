<?php
// Общая инициализация загрузчика: конфиг из окружения (.env рядом с worker/), БД, хелперы.

declare(strict_types=1);

const WORKER_ROOT = __DIR__ . '/..';

(function () {
    $f = WORKER_ROOT . '/.env';
    if (!is_file($f)) return;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if ($l[0] === '#' || !str_contains($l, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $l, 2));
        if (getenv($k) === false) putenv("$k=" . trim($v, "\"'"));
    }
})();

function cfg(string $key): string
{
    static $defaults = [
        'ALLOWED_ORIGINS'   => 'http://127.0.0.1:8090',
        'YTDLP_BIN'         => 'yt-dlp',
        'FFMPEG_BIN'        => 'ffmpeg',
        'DATA_DIR'          => WORKER_ROOT . '/data',
        'MAX_DURATION'      => '3600',       // секунд
        'MAX_FILESIZE'      => '1500M',
        'INFO_PER_HOUR'     => '20',         // запросов info с одного IP в час
        'DOWNLOADS_PER_HOUR'=> '10',
        'MAX_ACTIVE'        => '3',          // одновременных загрузок на весь сервис
        'FILE_TTL'          => '3600',       // сколько живёт готовый файл
        'TRUST_PROXY'       => '0',          // 1 — брать IP из X-Forwarded-For (за Caddy)
        'TURNSTILE_SECRET'  => '',
        'PLUGINS_DIR'       => WORKER_ROOT . '/plugins',   // плагин bgutil для PO-токенов YouTube
        'POT_URL'           => 'http://127.0.0.1:4416',    // сервер токенов; пусто — без токенов
        'INFO_TTL'          => '3600',       // сколько живёт кэш info и полный JSON для скачивания
        'YT_MIN_GAP'        => '1.5',        // секунд между запросами к YouTube со всего сервиса
        'YT_BACKOFF'        => '900',        // пауза после блока «not a bot» / 429, секунд
    ];
    $v = getenv($key);
    return $v === false || $v === '' ? ($defaults[$key] ?? '') : $v;
}

function data_dir(string $sub = ''): string
{
    $d = rtrim(cfg('DATA_DIR'), '/') . ($sub ? "/$sub" : '');
    if (!is_dir($d)) mkdir($d, 0775, true);
    return $d;
}

function db(): PDO
{
    static $pdo;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . data_dir() . '/db.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    $pdo->exec('CREATE TABLE IF NOT EXISTS hits (ip TEXT, kind TEXT, ts INTEGER);
        CREATE INDEX IF NOT EXISTS hits_ip ON hits (ip, kind, ts);
        CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, ip TEXT, status TEXT, file TEXT, name TEXT,
            created INTEGER, heartbeat INTEGER);
        CREATE TABLE IF NOT EXISTS info_cache (url TEXT PRIMARY KEY, json TEXT, ts INTEGER);
        CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT);');
    return $pdo;
}

function meta_get(string $k): ?string
{
    $st = db()->prepare('SELECT v FROM meta WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function meta_set(string $k, string $v): void
{
    db()->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)')->execute([$k, $v]);
}

function client_ip(): string
{
    if (cfg('TRUST_PROXY') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = array_map('trim', explode(',', cfg('ALLOWED_ORIGINS')));
    if ($origin && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Expose-Headers: Content-Disposition');
    }
}

/** Ошибка для клиента: код + понятный текст. */
final class UserError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require __DIR__ . '/UrlValidator.php';
require __DIR__ . '/RateLimiter.php';
require __DIR__ . '/Ytdlp.php';
require __DIR__ . '/FormatMapper.php';
require __DIR__ . '/Jobs.php';
require __DIR__ . '/Turnstile.php';
require __DIR__ . '/YoutubeGuard.php';
