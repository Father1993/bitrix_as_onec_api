<?php

/**
 * Подключение модуля as.onecstock.
 *
 * PSR-4: As\Onecstock → lib/ (см. .settings.php).
 *
 * Процедурный include/stock_import_engine.php подключается явно: глобальные функции asStockImportFrom1c*
 * нужны для HTTP-эндпоинтов, агентов и include public/http_stocks_import.php после prolog. Загрузка при
 * каждом includeModule — осознанный компромисс.
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

require_once __DIR__ . '/include/stock_import_engine.php';
require_once __DIR__ . '/lib/installer.php';

\As\Onecstock\Installer::syncIfNewVersion();
