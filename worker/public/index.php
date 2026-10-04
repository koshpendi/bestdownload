<?php
// API загрузчика: POST /info, GET /download (SSE), GET /file, GET /health.

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

cors();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$route = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

try {
    match ($route) {
        '/info'     => route_info(),
        '/download' => route_download(),
        '/file'     => route_file(),
        '/health'   => json_out(['ok' => true]),
        default     => json_out(['error' => 'Not found'], 404),
    };
} catch (UserError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log((string) $e);
    json_out(['error' => 'Внутренняя ошибка. Попробуйте позже.'], 500);
}

function route_info(): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST only'], 405);
    if (random_int(1, 20) === 1) Jobs::cleanup(); // подстраховка, если cron не настроен

    [$url, $platform] = UrlValidator::check((string) ($_POST['url'] ?? ''));
    RateLimiter::hit('info', (int) cfg('INFO_PER_HOUR'));
    $info = Ytdlp::info($url);

    if (!empty($info['is_live']) || ($info['live_status'] ?? '') === 'is_upcoming') {
        throw new UserError('Прямые эфиры не поддерживаются.', 422);
    }
    if (($info['duration'] ?? 0) > (int) cfg('MAX_DURATION')) {
        throw new UserError('Ролики длиннее ' . intdiv((int) cfg('MAX_DURATION'), 60) . ' минут не поддерживаются.', 422);
    }
    json_out([
        'url'       => $url,
        'platform'  => $platform,
        'title'     => $info['title'] ?? 'Без названия',
        'author'    => $info['uploader'] ?? $info['channel'] ?? null,
        'duration'  => isset($info['duration']) ? (int) round($info['duration']) : null,
        'thumbnail' => $info['thumbnail'] ?? null,
        'options'   => FormatMapper::options($info),
    ]);
}

function sse(string $event, array $data): void
{
    echo "event: $event\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush();
    flush();
}

function route_download(): never
{
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    while (ob_get_level()) ob_end_flush();
    ignore_user_abort(true); // обрыв ловим сами, чтобы убить yt-dlp
    set_time_limit(0);
    echo "retry: 86400000\n\n"; // EventSource не должен сам перезапускать загрузку

    $jobId = null;
    try {
        [$url] = UrlValidator::check((string) ($_GET['url'] ?? ''));
        $option = (string) ($_GET['option'] ?? '');
        if (!preg_match('~^(v\d{3,4}|m4a|mp3)$~', $option)) throw new UserError('Неизвестный формат.');
        Turnstile::verify((string) ($_GET['captcha'] ?? ''));
        RateLimiter::hit('download', (int) cfg('DOWNLOADS_PER_HOUR'));
        $info = Ytdlp::info($url);
        $jobId = Jobs::create();
        $dir = Jobs::dir($jobId);
        mkdir($dir, 0775, true);

        $yt = YoutubeGuard::applies($url);
        $infoFile = Ytdlp::infoFile($url);
        sse('progress', ['phase' => 'start']);
        if ($yt && !$infoFile) YoutubeGuard::before();
        [$code, $file, $err] = stream_ytdlp(Ytdlp::downloadCmd($url, $option, $dir, $infoFile), $jobId);
        if ($code !== 0 && $infoFile && preg_match('~HTTP Error 403|Forbidden|expired~i', $err)) {
            // Ссылки на потоки из сохранённого JSON протухли — повторяем по обычной ссылке.
            array_map('unlink', glob("$dir/*") ?: []);
            if ($yt) YoutubeGuard::before();
            [$code, $file, $err] = stream_ytdlp(Ytdlp::downloadCmd($url, $option, $dir), $jobId);
        }
        if ($code !== 0 && $yt) YoutubeGuard::after($err);

        $real = $file ? realpath($file) : false;
        if ($code !== 0 || !$real || !str_starts_with($real, realpath($dir) . '/')) {
            throw new UserError(Ytdlp::humanError($err ?: 'no file'), 422);
        }
        $name = download_name($info['title'] ?? 'video', $option, pathinfo($real, PATHINFO_EXTENSION));
        Jobs::finish($jobId, 'done', $real, $name);
        sse('done', ['id' => $jobId, 'name' => $name, 'size' => filesize($real)]);
    } catch (UserError $e) {
        if ($jobId) Jobs::finish($jobId, 'failed');
        sse('fail', ['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log((string) $e);
        if ($jobId) Jobs::finish($jobId, 'failed');
        sse('fail', ['error' => 'Внутренняя ошибка. Попробуйте позже.']);
    }
    exit;
}

/**
 * Запускает yt-dlp и пересылает его прогресс клиенту событиями SSE.
 * При обрыве соединения убивает процесс и завершает запрос.
 * @return array{0:int,1:?string,2:string} [код выхода, путь к файлу, stderr]
 */
function stream_ytdlp(array $cmd, string $jobId): array
{
    [$proc, $pipes] = Ytdlp::spawn($cmd);
    $kill = function () use (&$proc) {
        if (is_resource($proc) && proc_get_status($proc)['running']) proc_terminate($proc, 9);
    };
    register_shutdown_function($kill);

    $buf = $err = '';
    $file = null;
    $formats = [];
    $lastPing = $lastBeat = $t0 = time();
    while (true) {
        $r = [$pipes[1], $pipes[2]]; $w = $e = null;
        if (@stream_select($r, $w, $e, 1) > 0) {
            $buf .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = rtrim(substr($buf, 0, $pos), "\r");
                $buf = substr($buf, $pos + 1);
                if (str_starts_with($line, 'BDP|')) {
                    [, $fmt, $done, $total, $est, $speed, $eta] = explode('|', $line) + array_fill(0, 7, 'NA');
                    if (!in_array($fmt, $formats, true)) $formats[] = $fmt;
                    $total = is_numeric($total) ? (float) $total : (is_numeric($est) ? (float) $est : 0);
                    sse('progress', [
                        'phase' => 'download',
                        'part'  => count($formats),
                        'pct'   => $total > 0 && is_numeric($done) ? round(min(100, $done / $total * 100), 1) : null,
                        'speed' => is_numeric($speed) ? (int) $speed : null,
                        'eta'   => is_numeric($eta) ? (int) $eta : null,
                    ]);
                    $lastPing = time();
                } elseif (str_starts_with($line, 'BDPP|')) {
                    [, $pp] = explode('|', $line) + [1 => ''];
                    sse('progress', ['phase' => in_array($pp, ['Merger', 'FFmpegMerger'], true) ? 'merge' : 'convert']);
                    $lastPing = time();
                } elseif (str_starts_with($line, 'BDFILE|')) {
                    $file = substr($line, 7);
                }
            }
        }
        if (time() - $lastPing >= 5) { echo ": ping\n\n"; @ob_flush(); flush(); $lastPing = time(); }
        if (connection_aborted()) { $kill(); Jobs::finish($jobId, 'aborted'); exit; }
        if (time() - $lastBeat >= 5) { Jobs::beat($jobId); $lastBeat = time(); }
        if (time() - $t0 > 1800) { $kill(); throw new UserError('Загрузка заняла слишком долго.', 504); }
        $st = proc_get_status($proc);
        if (!$st['running']) break;
    }
    $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    return [$st['exitcode'], $file, $err];
}

function download_name(string $title, string $option, string $ext): string
{
    $t = preg_replace('~[\\\\/:*?"<>|\x00-\x1F]+~u', ' ', $title);
    $t = trim(preg_replace('~\s+~u', ' ', $t)) ?: 'video';
    $t = mb_substr($t, 0, 100);
    $suffix = str_starts_with($option, 'v') ? ' (' . substr($option, 1) . 'p)' : '';
    return "$t$suffix.$ext";
}

function route_file(): never
{
    $id = (string) ($_GET['id'] ?? '');
    $job = preg_match('~^[0-9a-f]{24}$~', $id) ? Jobs::get($id) : null;
    if (!$job || $job['status'] !== 'done' || !is_file((string) $job['file'])) {
        throw new UserError('Файл не найден или уже удалён. Скачайте заново.', 404);
    }
    $path = $job['file'];
    $size = filesize($path);
    $name = $job['name'];
    $ascii = preg_replace('~[^\x20-\x7E]|["\\\\]~', '_', $name);
    $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
        'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg', default => 'application/octet-stream',
    };

    $start = 0;
    $end = $size - 1;
    if (preg_match('~^bytes=(\d*)-(\d*)$~', $_SERVER['HTTP_RANGE'] ?? '', $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); }
        else { $start = (int) $m[1]; if ($m[2] !== '') $end = min($end, (int) $m[2]); }
        if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header("Content-Type: $mime");
    header('Content-Length: ' . ($end - $start + 1));
    header('Accept-Ranges: bytes');
    header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: private, no-store');

    set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) {
        $chunk = fread($fh, (int) min(1 << 20, $left));
        echo $chunk;
        flush();
        $left -= strlen($chunk);
    }
    fclose($fh);
    exit;
}
