<?php
// Раз в 10 минут: удаляет готовые файлы старше FILE_TTL. Раз в сутки: обновляет yt-dlp.
// В Docker запускается циклом из docker-compose, на обычном сервере — из crontab.

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$n = Jobs::cleanup();
echo date('c') . " cleanup: $n\n";

$st = db()->query("SELECT v FROM meta WHERE k = 'ytdlp_updated'");
$last = (int) $st->fetchColumn();
if (getenv('YTDLP_SELF_UPDATE') === '1' && time() - $last > 86400) {
    [$code, $out, $err] = Ytdlp::runSync([cfg('YTDLP_BIN'), '-U'], 300);
    echo "yt-dlp -U ($code): " . trim($out . $err) . "\n";
    db()->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('ytdlp_updated', ?)")->execute([time()]);
}
