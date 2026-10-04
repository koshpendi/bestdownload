<?php

declare(strict_types=1);

/** Превращает сырой список форматов в короткий набор понятных кнопок. */
final class FormatMapper
{
    private const STEPS = [2160, 1440, 1080, 720, 480, 360];

    public static function options(array $info): array
    {
        $formats = $info['formats'] ?? [];
        $audio = array_filter($formats, fn($f) => ($f['vcodec'] ?? 'none') === 'none' && ($f['acodec'] ?? 'none') !== 'none');
        $video = array_filter($formats, fn($f) => isset($f['height'], $f['width']) && ($f['vcodec'] ?? '') !== 'none');

        $bestAudio = self::bestSize($audio, fn($f) => ($f['ext'] ?? '') === 'm4a');

        // Разрешение — по меньшей стороне, как у yt-dlp -S res: вертикальный 1080×1920 это 1080p.
        $byRes = [];
        foreach ($video as $f) $byRes[min($f['width'], $f['height'])][] = $f;
        krsort($byRes);
        $present = array_keys($byRes);

        $options = [];
        foreach (self::STEPS as $step) {
            // Ступень показываем, если есть формат этого разрешения (±10% — у IG бывают 1078 и т.п.).
            $match = null;
            foreach ($present as $r) if ($r <= $step * 1.1 && $r >= $step * 0.9) { $match = $r; break; }
            if ($match === null) continue;
            $vf = $byRes[$match];
            $hasAudio = (bool) array_filter($vf, fn($f) => ($f['acodec'] ?? 'none') !== 'none');
            $size = self::bestSize($vf, fn($f) => str_starts_with((string) ($f['vcodec'] ?? ''), 'avc'));
            if ($size && !$hasAudio && $bestAudio) $size += $bestAudio;
            $options[] = ['id' => "v$step", 'kind' => 'video', 'label' => self::label($step), 'size' => $size];
        }
        // Нестандартное разрешение (например 640 у рилса) — одна кнопка с фактическим.
        if (!$options && $present) {
            $r = $present[0];
            $options[] = ['id' => "v$r", 'kind' => 'video', 'label' => "{$r}p", 'size' => self::bestSize($byRes[$r], fn() => true)];
        }
        if ($options) $options[0]['best'] = true;

        if ($audio || $video) {
            $options[] = ['id' => 'm4a', 'kind' => 'audio', 'label' => 'M4A', 'size' => $bestAudio];
            $dur = (float) ($info['duration'] ?? 0);
            $options[] = ['id' => 'mp3', 'kind' => 'audio', 'label' => 'MP3', 'size' => $dur ? (int) ($dur * 320000 / 8) : null];
        }
        return $options;
    }

    private static function label(int $step): string
    {
        return match ($step) { 2160 => '4K', 1440 => '2K', default => "{$step}p" };
    }

    /** Размер предпочтительного формата из группы (или самого крупного, если предпочтительного нет). */
    private static function bestSize(array $formats, callable $prefer): ?int
    {
        $sizes = fn($list) => array_filter(array_map(fn($f) => $f['filesize'] ?? $f['filesize_approx'] ?? null, $list));
        $pref = $sizes(array_filter($formats, $prefer));
        $all = $sizes($formats);
        $pick = $pref ?: $all;
        return $pick ? (int) max($pick) : null;
    }
}
