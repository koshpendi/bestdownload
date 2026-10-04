<?php

declare(strict_types=1);

/** Пропускает только ссылки YouTube и Instagram и приводит их к каноническому виду. */
final class UrlValidator
{
    private const HOSTS = [
        'youtube.com' => 'youtube', 'www.youtube.com' => 'youtube', 'm.youtube.com' => 'youtube',
        'music.youtube.com' => 'youtube', 'youtu.be' => 'youtube',
        'instagram.com' => 'instagram', 'www.instagram.com' => 'instagram',
    ];

    /** @return array{0: string, 1: string} [нормализованная ссылка, платформа] */
    public static function check(string $raw): array
    {
        $raw = trim($raw);
        if ($raw !== '' && !preg_match('~^https?://~i', $raw)) $raw = 'https://' . $raw;
        $p = parse_url($raw);
        $host = strtolower($p['host'] ?? '');
        if (!$p || !isset(self::HOSTS[$host]) || strlen($raw) > 500) {
            throw new UserError('Нужна ссылка на YouTube или Instagram.');
        }
        $platform = self::HOSTS[$host];
        $path = $p['path'] ?? '/';
        parse_str($p['query'] ?? '', $q);

        if ($platform === 'youtube') {
            $id = null;
            if ($host === 'youtu.be') $id = trim($path, '/');
            elseif (!empty($q['v'])) $id = (string) $q['v'];
            elseif (preg_match('~^/(shorts|live|embed)/([\w-]{11})~', $path, $m)) $id = $m[2];
            if (!$id || !preg_match('~^[\w-]{11}$~', $id)) {
                throw new UserError('Не вижу в ссылке ролика YouTube. Плейлисты и каналы не поддерживаются.');
            }
            return ["https://www.youtube.com/watch?v=$id", $platform];
        }

        if (!preg_match('~^/(?:[\w.]+/)?(p|reel|reels|tv)/([\w-]+)~', $path, $m)) {
            throw new UserError('Нужна ссылка на пост или рилс Instagram. Профили и сторис не поддерживаются.');
        }
        $kind = $m[1] === 'reels' ? 'reel' : $m[1];
        return ["https://www.instagram.com/$kind/{$m[2]}/", $platform];
    }
}
