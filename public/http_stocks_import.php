<?php

/**
 * Подключать после prolog (агент, внутренний сценарий). По умолчанию без проверки ключа импорта.
 */

defined('B_PROLOG_INCLUDED') || die();

if (!defined('ONEC_STOCK_IMPORT_SKIP_AUTH')) {
    define('ONEC_STOCK_IMPORT_SKIP_AUTH', true);
}

require_once __DIR__ . '/../include/http_import_response.php';
