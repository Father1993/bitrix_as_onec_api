<?php

/**
 * Подключение модуля as.onec_api (1C JSON API: остатки, цены, товары).
 *
 * PSR-4: As\OnecApi → lib/ (см. .settings.php).
 *
 * Процедурный {@see include/stock_import_engine.php} не подключается здесь: ленивая загрузка через
 * {@see \As\OnecApi\Stock\StockEngineBootstrap::ensureLoaded()} при обработке HTTP-маршрутов (см. JsonApiKernel).
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

require_once __DIR__ . '/lib/installer.php';

\As\OnecApi\Installer::syncIfNewVersion();
