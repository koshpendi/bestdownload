<?php
$cfg = require __DIR__ . '/config.php';
$v = @filemtime(__DIR__ . '/assets/app.js') . @filemtime(__DIR__ . '/assets/app.css');
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BestDownload — скачать видео с YouTube и Instagram</title>
<meta name="description" content="Скачивайте ролики YouTube, Shorts и рилсы Instagram в MP4 до 4K или только звук в MP3 и M4A. Бесплатно и без регистрации.">
<meta name="theme-color" content="#0f1115" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#f6f5f2" media="(prefers-color-scheme: light)">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23ff5a36'/><path d='M16 7v13m0 0-5-5m5 5 5-5M9 25h14' stroke='white' stroke-width='3' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
<?php if ($cfg['turnstile_sitekey']): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
<?php endif ?>
</head>
<body>
<main class="wrap">
  <header class="top">
    <a class="brand" href="./">
      <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 20h14"/></svg></span>
      BestDownload
    </a>
  </header>

  <section class="hero">
    <h1>Скачать видео<br>с YouTube и Instagram</h1>
    <p class="lead">Вставьте ссылку на ролик, Shorts или рилс. Видео до 4K, или только звук в MP3.</p>

    <form id="form" class="search" autocomplete="off">
      <input id="url" type="url" inputmode="url" placeholder="https://youtube.com/watch?v=…" aria-label="Ссылка на видео" required>
      <button type="button" id="paste" class="ghost" title="Вставить из буфера">Вставить</button>
      <button type="submit" id="go" class="primary">Найти</button>
    </form>
    <p id="error" class="error" role="alert" hidden></p>
  </section>

  <section id="card" class="card" hidden>
    <div class="media">
      <div class="thumb"><img id="thumb" alt="" referrerpolicy="no-referrer"><span id="dur" class="dur"></span></div>
      <div class="meta">
        <span id="platform" class="platform"></span>
        <h2 id="title"></h2>
        <p id="author" class="author"></p>
      </div>
    </div>

    <div id="choose">
      <div class="group"><h3>Видео · MP4</h3><div id="video" class="chips"></div></div>
      <div class="group"><h3>Только звук</h3><div id="audio" class="chips"></div></div>
      <div id="captcha" class="captcha"></div>
    </div>

    <div id="progress" class="progress" hidden>
      <div class="progress-head"><span id="phase">Готовлю…</span><span id="pct"></span></div>
      <div class="bar"><div id="fill"></div></div>
      <div class="progress-foot"><span id="stats"></span><button type="button" id="cancel" class="link">Отменить</button></div>
    </div>

    <div id="done" class="done" hidden>
      <div class="done-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></div>
      <div class="done-text"><strong>Готово</strong><span id="doneName"></span></div>
      <a id="again" class="primary small" href="#">Скачать ещё раз</a>
      <button type="button" id="other" class="link">Другой формат</button>
    </div>
  </section>

  <section class="how">
    <div><b>1</b><p>Скопируйте ссылку в приложении YouTube или Instagram</p></div>
    <div><b>2</b><p>Вставьте её сюда и выберите качество</p></div>
    <div><b>3</b><p>Файл сохранится в «Загрузки»</p></div>
  </section>

  <footer class="foot">
    <p>Скачивайте только то, на что у вас есть права: свои ролики или контент с разрешения автора. Сервис не хранит файлы дольше часа. Приватные посты, сторис и прямые эфиры не поддерживаются.</p>
    <p>© <?= date('Y') ?> <a href="/">anuarkhanov.com</a></p>
  </footer>
</main>
<script>window.BD = <?= json_encode(['api' => rtrim($cfg['api_base'], '/'), 'sitekey' => $cfg['turnstile_sitekey']], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
