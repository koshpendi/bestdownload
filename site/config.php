<?php
// Настройки страницы. На ps.kz поменять API_BASE на адрес загрузчика (например https://dl.anuarkhanov.com).
return [
    'api_base'          => getenv('BD_API_BASE') ?: 'http://127.0.0.1:8092',
    'turnstile_sitekey' => getenv('BD_TURNSTILE_SITEKEY') ?: '',   // пусто — проверка «не робот» выключена
];
