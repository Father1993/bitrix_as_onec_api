<?php

/**
 * HTTP-обработчик POST импорта остатков (подключается после prolog_before.php).
 * Основной внешний сценарий для 1С — вебхук REST {@see \As\Onecstock\Rest\StockImportService::METHOD}.
 * Этот файл — вспомогательный include (тест, агент, опциональный тонкий прокси в корне сайта).
 */

defined('B_PROLOG_INCLUDED') || die();

use Bitrix\Main\Loader;

if (!defined('ONEC_STOCK_IMPORT_SKIP_AUTH')) {
    define('ONEC_STOCK_IMPORT_SKIP_AUTH', true);
}

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(
        [
            'ok' => false,
            'error' => 'METHOD_NOT_ALLOWED',
            'message' => 'Используйте метод POST.',
        ],
        JSON_UNESCAPED_UNICODE
    );
    return;
}

if (!Loader::includeModule('as.onecstock')) {
    http_response_code(500);
    echo json_encode(
        [
            'ok' => false,
            'error' => 'MODULE',
            'message' => 'Модуль as.onecstock не установлен.',
        ],
        JSON_UNESCAPED_UNICODE
    );
    return;
}

$result = asStockImportFrom1cRun();
http_response_code($result['http_code']);
echo json_encode($result['data'], JSON_UNESCAPED_UNICODE);
