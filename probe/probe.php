<?php
// BestDownload — проба площадки ps.kz. Кладётся в httpdocs/public/bestdownload/probe.php (docroot = httpdocs/public).
// Открывать: /bestdownload/probe.php?t=TOKEN          — базовый отчёт
//            &fetch=1                                  — скачать yt-dlp и ffmpeg в bin/
//            &long=1                                   — держать процесс 60 с в запросе
//            &ig=<ссылка на публичный рилс>            — проверить Instagram
// После пробы файл удалить (или перенести в _to_delete).

const TOKEN = 'c81b21b050c517512467e087';
const YT_TEST = 'https://www.youtube.com/watch?v=jNQXAC9IVRw';
const YTDLP_URL = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux';
const FFMPEG_URL = 'https://github.com/eugeneware/ffmpeg-static/releases/download/b6.0/ffmpeg-linux-x64.gz';

if (($_GET['t'] ?? '') !== TOKEN) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

$home = dirname(__DIR__, 3);                 // httpdocs/public/bestdownload → домашняя папка
$app  = $home . '/bestdownload-app';
$bin  = $app . '/bin';
$tmp  = $app . '/data/tmp';
$ytdlp  = $bin . '/yt-dlp_linux';
$ffmpeg = $bin . '/ffmpeg';

function line(string $k, $v): void { echo $k . str_repeat(' ', max(1, 29 - mb_strlen($k))) . (is_bool($v) ? ($v ? 'да' : 'НЕТ') : $v) . "\n"; flush(); }
function head(string $t): void { echo "\n== $t ==\n"; flush(); }
function fn_ok(string $f): bool {
    $dis = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return function_exists($f) && !in_array($f, $dis, true);
}

/** Запуск команды массивом аргументов; возвращает [код, stdout, stderr, секунды]. */
function run(array $cmd, int $timeout = 120, array $env = []): array {
    if (!fn_ok('proc_open')) return [-1, '', 'proc_open отключён', 0];
    $t0 = microtime(true);
    $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env ?: null);
    if (!is_resource($p)) return [-1, '', 'proc_open не запустил процесс', 0];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    while (true) {
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) break;
        if (microtime(true) - $t0 > $timeout) { proc_terminate($p, 9); $err .= "\n[таймаут $timeout с]"; break; }
        usleep(100000);
    }
    $out .= stream_get_contents($pipes[1]);
    $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    if (isset($st) && !$st['running'] && $st['exitcode'] !== -1) $code = $st['exitcode'];
    return [$code, $out, $err, round(microtime(true) - $t0, 2)];
}

function fetch(string $url, string $dest): string {
    if (!function_exists('curl_init')) return 'нет расширения curl';
    $fh = fopen($dest . '.part', 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300,
        CURLOPT_USERAGENT => 'bestdownload-probe']);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $e = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if (!$ok || $code !== 200) return "ошибка: HTTP $code $e";
    rename($dest . '.part', $dest);
    return 'ok, ' . round(filesize($dest) / 1048576, 1) . ' МБ';
}

echo "BestDownload probe  " . date('c') . "\n";

head('PHP');
line('версия', PHP_VERSION . ' (' . PHP_SAPI . ')');
line('ОС', php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'));
line('disable_functions', ini_get('disable_functions') ?: '(пусто)');
line('open_basedir', ini_get('open_basedir') ?: '(нет)');
line('max_execution_time', ini_get('max_execution_time'));
@set_time_limit(0);
line('set_time_limit(0) сработал', ini_get('max_execution_time') === '0');
line('memory_limit', ini_get('memory_limit'));
line('upload/post max', ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'));
foreach (['proc_open', 'exec', 'shell_exec', 'proc_terminate', 'posix_kill'] as $f) line($f, fn_ok($f));
foreach (['pdo_sqlite', 'curl', 'zlib', 'mbstring'] as $e) line("ext $e", extension_loaded($e));
line('домашняя папка', $home);
line('свободно на диске', is_dir($home) ? round(@disk_free_space($home) / 1073741824, 2) . ' ГБ' : '?');
line('sys_get_temp_dir', sys_get_temp_dir());

head('Папки');
foreach ([$app, $bin, $tmp] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
    line(str_replace($home, '~', $d), is_dir($d) && is_writable($d));
}

head('Исполнение файлов');
foreach (['/tmp' => sys_get_temp_dir(), 'app/data/tmp' => $tmp] as $label => $dir) {
    $sh = $dir . '/bd_probe_' . getmypid() . '.sh';
    if (@file_put_contents($sh, "#!/bin/sh\necho works\n") === false) { line("скрипт в $label", 'не записать'); continue; }
    chmod($sh, 0755);
    [$c, $o, $e] = run([$sh], 10);
    line("скрипт в $label", trim($o) === 'works' ? 'исполняется' : "НЕ исполняется ($c " . trim($e) . ')');
    @unlink($sh);
}

head('Жизнь процесса в запросе');
[$c, , $e, $s] = run(['sleep', '5'], 20);
line('sleep 5', "код $c, прожил {$s} с" . ($e ? " / $e" : ''));
if (isset($_GET['long'])) {
    [$c, , $e, $s] = run(['sleep', '60'], 90);
    line('sleep 60', "код $c, прожил {$s} с" . ($e ? " / $e" : ''));
}
// Фоновый процесс: пишет метку через 20 с. Результат виден при следующем открытии пробы.
$marker = $tmp . '/bg_marker';
line('метка прошлого фон. запуска', is_file($marker) ? 'ЕСТЬ (' . trim(file_get_contents($marker)) . ') — фон выживает' : 'нет');
@unlink($marker);
if (fn_ok('exec')) {
    @exec('nohup sh -c ' . escapeshellarg('sleep 20; date > ' . escapeshellarg($marker)) . ' > /dev/null 2>&1 &');
    line('фоновый тест', 'запущен — открой пробу снова через 30 с');
}

if (isset($_GET['fetch'])) {
    head('Скачивание бинарей');
    line('yt-dlp_linux', fetch(YTDLP_URL, $ytdlp));
    @chmod($ytdlp, 0755);
    $gz = $ffmpeg . '.gz';
    $r = fetch(FFMPEG_URL, $gz);
    if (str_starts_with($r, 'ok')) {
        $data = @gzdecode(file_get_contents($gz));
        $r = $data === false ? 'не распаковался' : 'ok, ' . round(strlen($data) / 1048576, 1) . ' МБ';
        if ($data !== false) file_put_contents($ffmpeg, $data);
        @unlink($gz);
        unset($data);
    }
    line('ffmpeg', $r);
    @chmod($ffmpeg, 0755);
}

head('Бинари');
$env = ['TMPDIR' => $tmp, 'HOME' => $app, 'PATH' => '/usr/local/bin:/usr/bin:/bin'];
if (is_file($ytdlp)) {
    [$c, $o, $e, $s] = run([$ytdlp, '--version'], 60, $env);
    line('yt-dlp --version', $c === 0 ? trim($o) . " ({$s} с)" : "ошибка $c: " . trim($e));
} else line('yt-dlp', 'нет файла — открой с &fetch=1');
if (is_file($ffmpeg)) {
    [$c, $o, $e] = run([$ffmpeg, '-version'], 30, $env);
    line('ffmpeg -version', $c === 0 ? strtok($o, "\n") : "ошибка $c: " . trim($e));
} else line('ffmpeg', 'нет файла');

if (is_file($ytdlp)) {
    $tests = ['YouTube' => YT_TEST];
    if (!empty($_GET['ig'])) $tests['Instagram'] = (string) $_GET['ig'];
    foreach ($tests as $name => $url) {
        head("yt-dlp -J: $name");
        [$c, $o, $e, $s] = run([$ytdlp, '-J', '--no-playlist', '--no-warnings', $url], 120, $env);
        $j = json_decode($o, true);
        if ($c === 0 && is_array($j)) {
            $h = array_filter(array_map(fn($f) => $f['height'] ?? null, $j['formats'] ?? []));
            line('результат', 'ok за ' . $s . ' с');
            line('название', mb_substr($j['title'] ?? '?', 0, 60));
            line('форматов', count($j['formats'] ?? []) . ', макс. высота ' . ($h ? max($h) : '?'));
        } else {
            line('результат', "ОШИБКА (код $c, {$s} с)");
            echo mb_substr(trim($e), 0, 800) . "\n";
        }
    }
}

echo "\nГотово. Скопируй весь этот текст и пришли.\n";
