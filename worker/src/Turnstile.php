<?php

declare(strict_types=1);

/** Проверка Cloudflare Turnstile. Без TURNSTILE_SECRET проверка выключена. */
final class Turnstile
{
    public static function verify(string $token): void
    {
        $secret = cfg('TURNSTILE_SECRET');
        if ($secret === '') return;
        if ($token === '') throw new UserError('Подтвердите, что вы не робот.', 403);
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => client_ip()]),
        ]);
        $res = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        if (empty($res['success'])) throw new UserError('Проверка «не робот» не прошла. Обновите страницу.', 403);
    }
}
