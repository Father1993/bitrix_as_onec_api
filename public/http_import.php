<?php

/**
 * Прямой HTTP-endpoint импорта остатков (БУС). POST + JSON.
 * Авторизация: X-Stock-Import-Key / access_key / login+password (см. stock_import_engine.php).
 *
 * URL: .../local/modules/as.onec_api/public/http_import.php
 * Канонический API: /local/tools/as_onec_api.php; алиас импорта: /local/tools/as_onecstock_import.php
 */

define('STOP_STATISTICS', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_after.php';
}

require_once __DIR__ . '/../include/http_import_response.php';
