<?php

/**
 * Общий вывод JSON для HTTP-импорта остатков (POST → asStockImportFrom1cRun).
 * Подключать после ядра и (для прямого URL) после prolog.
 */

use Bitrix\Main\Loader;

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
