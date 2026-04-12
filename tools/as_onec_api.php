<?php

/**
 * Единственная точка входа JSON API `as.onec_api` (маршруты `path=/v1/...`, см. JsonApiKernel).
 */

define('STOP_STATISTICS', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_after.php';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/modules/as.onec_api/include/api_http_bootstrap.php';