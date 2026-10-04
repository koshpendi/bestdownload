(() => {
  const API = window.BD.api;
  const SITEKEY = window.BD.sitekey;
  const $ = (id) => document.getElementById(id);

  let current = null;   // ответ /info
  let es = null;        // активный EventSource
  let captchaId = null;
  let chosen = null;

  const fmtSize = (b) => {
    if (!b) return '';
    if (b < 1e6) return Math.max(1, Math.round(b / 1e3)) + ' КБ';
    if (b < 1e9) return (b / 1e6).toFixed(b < 1e8 ? 1 : 0).replace('.', ',') + ' МБ';
    return (b / 1e9).toFixed(2).replace('.', ',') + ' ГБ';
  };
  const fmtTime = (s) => {
    if (s == null) return '';
    const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), sec = String(s % 60).padStart(2, '0');
    return h ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
  };

  function showError(msg) {
    $('error').textContent = msg;
    $('error').hidden = !msg;
  }

  async function lookup(e) {
    e && e.preventDefault();
    const url = $('url').value.trim();
    if (!url) return;
    stopStream();
    showError('');
    $('go').disabled = true;
    $('go').textContent = 'Ищу…';
    try {
      const res = await fetch(API + '/info', { method: 'POST', body: new URLSearchParams({ url }) });
      const data = await res.json().catch(() => ({ error: 'Сервер ответил странно. Попробуйте позже.' }));
      if (!res.ok) throw new Error(data.error || 'Не получилось.');
      current = data;
      render();
    } catch (err) {
      $('card').hidden = true;
      showError(err.message === 'Failed to fetch' ? 'Сервер загрузки недоступен. Попробуйте позже.' : err.message);
    } finally {
      $('go').disabled = false;
      $('go').textContent = 'Найти';
    }
  }

  function render() {
    const d = current;
    $('thumb').src = d.thumbnail || '';
    $('thumb').hidden = !d.thumbnail;
    $('dur').textContent = fmtTime(d.duration);
    $('platform').textContent = d.platform === 'instagram' ? 'Instagram' : 'YouTube';
    $('title').textContent = d.title;
    $('author').textContent = d.author || '';

    for (const kind of ['video', 'audio']) {
      const box = $(kind);
      box.innerHTML = '';
      for (const o of d.options.filter((o) => o.kind === kind)) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'chip' + (o.best ? ' best' : '');
        b.innerHTML = `<b></b><small></small>`;
        b.querySelector('b').textContent = o.label;
        b.querySelector('small').textContent = (o.size ? '≈ ' + fmtSize(o.size) : '');
        if (!b.querySelector('small').textContent) b.querySelector('small').textContent = kind === 'audio' ? 'звук' : 'видео';
        b.addEventListener('click', () => start(o));
        box.appendChild(b);
      }
      box.closest('.group').hidden = !box.children.length;
    }

    $('choose').hidden = false;
    $('progress').hidden = true;
    $('done').hidden = true;
    $('card').hidden = false;
    mountCaptcha();
    $('card').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function mountCaptcha() {
    if (!SITEKEY || !window.turnstile) return;
    if (captchaId !== null) { turnstile.reset(captchaId); return; }
    captchaId = turnstile.render('#captcha', { sitekey: SITEKEY, theme: 'auto', language: 'ru', appearance: 'interaction-only' });
  }

  const phaseText = (p, o) => {
    if (p.phase === 'start') return 'Готовлю…';
    if (p.phase === 'merge') return 'Склеиваю видео и звук…';
    if (p.phase === 'convert') return o.kind === 'audio' ? `Конвертирую в ${o.label}…` : 'Обрабатываю…';
    if (o.kind === 'audio') return 'Скачиваю звук';
    return p.part > 1 ? 'Скачиваю звук' : 'Скачиваю видео';
  };

  function start(option) {
    let captcha = '';
    if (SITEKEY && window.turnstile && captchaId !== null) {
      captcha = turnstile.getResponse(captchaId) || '';
      if (!captcha) { showError('Подождите секунду: идёт проверка «не робот».'); return; }
    }
    showError('');
    chosen = option;
    $('choose').hidden = true;
    $('done').hidden = true;
    $('progress').hidden = false;
    setProgress({ phase: 'start' });

    const qs = new URLSearchParams({ url: current.url, option: option.id, captcha });
    es = new EventSource(API + '/download?' + qs);
    es.addEventListener('progress', (e) => setProgress(JSON.parse(e.data)));
    es.addEventListener('done', (e) => finish(JSON.parse(e.data)));
    es.addEventListener('fail', (e) => fail(JSON.parse(e.data).error));
    es.onerror = () => { if (es) fail('Связь с сервером прервалась. Попробуйте ещё раз.'); };
  }

  function setProgress(p) {
    $('phase').textContent = phaseText(p, chosen);
    const bar = $('fill').parentElement;
    const known = p.phase === 'download' && p.pct != null;
    bar.classList.toggle('indeterminate', !known);
    $('fill').style.width = known ? p.pct + '%' : '0';
    $('pct').textContent = known ? Math.floor(p.pct) + '%' : '';
    const bits = [];
    if (p.speed) bits.push(fmtSize(p.speed) + '/с');
    if (p.eta != null && p.phase === 'download') bits.push('осталось ' + fmtTime(p.eta));
    $('stats').textContent = bits.join(' · ');
  }

  function stopStream() {
    if (es) { const s = es; es = null; s.close(); }
  }

  function finish(d) {
    stopStream();
    const href = API + '/file?id=' + encodeURIComponent(d.id);
    $('progress').hidden = true;
    $('done').hidden = false;
    $('doneName').textContent = d.name + ' · ' + fmtSize(d.size);
    $('again').href = href;
    location.href = href; // attachment: страница остаётся на месте, браузер сохраняет файл
    if (captchaId !== null) turnstile.reset(captchaId);
  }

  function fail(msg) {
    stopStream();
    $('progress').hidden = true;
    $('choose').hidden = false;
    showError(msg);
    if (captchaId !== null) turnstile.reset(captchaId);
  }

  $('form').addEventListener('submit', lookup);
  $('cancel').addEventListener('click', () => { stopStream(); $('progress').hidden = true; $('choose').hidden = false; });
  $('other').addEventListener('click', () => { $('done').hidden = true; $('choose').hidden = false; });
  $('url').addEventListener('paste', () => setTimeout(lookup, 0));

  $('paste').addEventListener('click', async () => {
    try {
      const t = (await navigator.clipboard.readText()).trim();
      if (t) { $('url').value = t; lookup(); }
    } catch {
      $('url').focus();
      showError('Браузер не дал доступ к буферу — вставьте ссылку вручную.');
    }
  });

  // Поддержка ссылки вида ?url=… (например, из «Поделиться»).
  const pre = new URLSearchParams(location.search).get('url');
  if (pre) { $('url').value = pre; lookup(); }
})();
