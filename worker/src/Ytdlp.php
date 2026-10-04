<?php

declare(strict_types=1);

/** Запуск yt-dlp. Аргументы всегда массивом — без шелла. */
final class Ytdlp
{
    private static function base(): array
    {
        $cmd = [
            cfg('YTDLP_BIN'), '--no-playlist', '--no-warnings', '--ignore-config',
            '--cache-dir', data_dir('cache'), '--socket-timeout', '20', '--retries', '3',
        ];
        // PO-токены (bgutil): снижают число проверок «not a bot». Без плагина или сервера yt-dlp работает как обычно.
        if (is_dir(cfg('PLUGINS_DIR'))) array_push($cmd, '--plugin-dirs', cfg('PLUGINS_DIR'));
        if (cfg('POT_URL') !== '') array_push($cmd, '--extractor-args', 'youtubepot-bgutilhttp:base_url=' . cfg('POT_URL'));
        return $cmd;
    }

    /** Полный JSON ролика, сохранённый при info(); годится для --load-info-json, пока свежий. */
    public static function infoFile(string $url): ?string
    {
        $f = data_dir('info') . '/' . sha1($url) . '.json';
        return is_file($f) && time() - filemtime($f) < (int) cfg('INFO_TTL') ? $f : null;
    }

    private static function env(): array
    {
        return [
            'PATH' => (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') . ':/opt/homebrew/bin',
            'HOME' => data_dir(), 'TMPDIR' => data_dir('tmp'), 'LANG' => 'C.UTF-8',
        ];
    }

    /** Метаданные ролика (кэш INFO_TTL). */
    public static function info(string $url): array
    {
        $st = db()->prepare('SELECT json FROM info_cache WHERE url = ? AND ts > ?');
        $st->execute([$url, time() - (int) cfg('INFO_TTL')]);
        if ($cached = $st->fetchColumn()) return json_decode($cached, true);

        $yt = YoutubeGuard::applies($url);
        if ($yt) YoutubeGuard::before();
        $cmd = [...self::base(), '-J', $url];
        [$code, $out, $err] = self::runSync($cmd, 90);
        $j = json_decode($out, true);
        if ($code !== 0 || !is_array($j)) {
            if ($yt) YoutubeGuard::after($err);
            throw new UserError(self::humanError($err), 422);
        }

        // Карусель Instagram: берём первый элемент с видео.
        if (($j['_type'] ?? '') === 'playlist') {
            $video = null;
            foreach ($j['entries'] ?? [] as $e) {
                if (!empty($e['formats']) && self::hasVideo($e)) { $video = $e; break; }
            }
            if (!$video) throw new UserError('В этом посте нет видео.', 422);
            $video['title'] = $video['title'] ?? $j['title'] ?? null;
            $j = $video;
        }
        if (!self::hasVideo($j) && !self::hasAudio($j)) throw new UserError('В этом посте нет видео.', 422);

        // Полный JSON — для скачивания без повторного запроса к источнику.
        file_put_contents(data_dir('info') . '/' . sha1($url) . '.json', json_encode($j));

        // Храним только то, что нужно, — полный JSON YouTube весит мегабайты.
        $slim = array_intersect_key($j, array_flip(['id', 'title', 'uploader', 'channel', 'duration',
            'thumbnail', 'is_live', 'live_status', 'extractor_key', 'webpage_url', 'width', 'height']));
        $slim['formats'] = array_map(fn($f) => array_intersect_key($f, array_flip(['format_id', 'ext',
            'width', 'height', 'vcodec', 'acodec', 'filesize', 'filesize_approx', 'tbr', 'abr'])), $j['formats'] ?? []);

        db()->prepare('INSERT OR REPLACE INTO info_cache (url, json, ts) VALUES (?, ?, ?)')
            ->execute([$url, json_encode($slim), time()]);
        return $slim;
    }

    private static function hasVideo(array $j): bool
    {
        foreach ($j['formats'] ?? [] as $f) if (($f['vcodec'] ?? 'none') !== 'none' || isset($f['height'])) return true;
        return false;
    }

    private static function hasAudio(array $j): bool
    {
        foreach ($j['formats'] ?? [] as $f) if (($f['acodec'] ?? 'none') !== 'none') return true;
        return false;
    }

    /** Аргументы для скачивания выбранного варианта. */
    public static function downloadCmd(string $url, string $option, string $outDir, ?string $infoFile = null): array
    {
        $cmd = [...self::base(),
            '--newline', '--progress', '--no-mtime', '--no-part',
            '--max-filesize', cfg('MAX_FILESIZE'),
            '--match-filter', '!is_live & duration <=? ' . (int) cfg('MAX_DURATION'),
            '--progress-template', 'download:BDP|%(info.format_id)s|%(progress.downloaded_bytes)s|%(progress.total_bytes)s|%(progress.total_bytes_estimate)s|%(progress.speed)s|%(progress.eta)s',
            '--progress-template', 'postprocess:BDPP|%(progress.postprocessor)s|%(progress.status)s',
            '--print', 'after_move:BDFILE|%(filepath)s',
            '-o', $outDir . '/media.%(ext)s',
        ];
        // Имя без пути yt-dlp ищет в PATH сам; --ffmpeg-location принимает только путь.
        if (str_contains(cfg('FFMPEG_BIN'), '/')) array_push($cmd, '--ffmpeg-location', cfg('FFMPEG_BIN'));
        if (preg_match('~^v(\d{3,4})$~', $option, $m)) {
            // Сначала разрешение, потом h264/aac — такой mp4 откроется везде, включая QuickTime.
            array_push($cmd, '-f', 'bv*+ba/b', '-S', "res:{$m[1]},vcodec:h264,acodec:aac,ext:mp4:m4a",
                '--merge-output-format', 'mp4');
        } elseif ($option === 'm4a') {
            array_push($cmd, '-f', 'ba[ext=m4a]/ba', '-x', '--audio-format', 'm4a');
        } elseif ($option === 'mp3') {
            array_push($cmd, '-f', 'ba', '-x', '--audio-format', 'mp3', '--audio-quality', '0');
        } else {
            throw new UserError('Неизвестный формат.');
        }
        // С сохранённым JSON yt-dlp не ходит за страницей ролика, а сразу качает поток.
        array_push($cmd, ...($infoFile ? ['--load-info-json', $infoFile] : [$url]));
        return $cmd;
    }

    /** Запускает процесс и возвращает [ресурс, pipes]. */
    public static function spawn(array $cmd): array
    {
        $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::env());
        if (!is_resource($p)) throw new UserError('Загрузчик не запустился.', 500);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return [$p, $pipes];
    }

    /** @return array{0:int,1:string,2:string} */
    public static function runSync(array $cmd, int $timeout): array
    {
        [$p, $pipes] = self::spawn($cmd);
        $out = $err = '';
        $t0 = time();
        do {
            $r = [$pipes[1], $pipes[2]]; $w = $e = null;
            if (@stream_select($r, $w, $e, 1) > 0) {
                $out .= stream_get_contents($pipes[1]);
                $err .= stream_get_contents($pipes[2]);
            }
            $st = proc_get_status($p);
            if (time() - $t0 > $timeout) { proc_terminate($p, 9); $err .= ' timeout'; break; }
        } while ($st['running']);
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        return [$st['running'] ? -1 : ($st['exitcode'] >= 0 ? $st['exitcode'] : $code), $out, $err];
    }

    /** Переводит stderr yt-dlp в понятную фразу. */
    public static function humanError(string $err): string
    {
        $map = [
            'confirm you.re not a bot|Sign in to confirm|HTTP Error 429|Too Many Requests' => 'YouTube временно не пускает сервер. Попробуйте позже.',
            'Private video|This video is private|is private' => 'Это приватный контент.',
            'confirm your age|age-restricted|inappropriate for some users' => 'Ролик с возрастным ограничением — без входа его не скачать.',
            'members-only|Join this channel' => 'Ролик только для спонсоров канала.',
            'Video unavailable|not available|removed|does not exist|404' => 'Ролик недоступен или удалён.',
            'login required|rate-limit|Requested content is not available|empty media response' =>
                'Instagram не отдаёт этот пост без входа. Попробуйте позже или другой пост.',
            'does not pass filter|is_live' => 'Прямые эфиры и ролики длиннее часа не поддерживаются.',
            'larger than max-filesize|File is larger' => 'Файл слишком большой.',
            'timeout|timed out' => 'Источник не ответил вовремя. Попробуйте ещё раз.',
        ];
        foreach ($map as $re => $msg) if (preg_match("~$re~iu", $err)) return $msg;
        error_log('yt-dlp: ' . substr(trim($err), 0, 1000));
        return 'Не получилось обработать ссылку.';
    }
}
