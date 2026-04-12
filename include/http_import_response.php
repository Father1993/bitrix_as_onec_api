<?php

/**
 * Общий вывод JSON для HTTP-импорта остатков (POST → JsonApiKernel /v1/stocks/import).
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
            'api_version' => '1',
        ],
        JSON_UNESCAPED_UNICODE
    );

    return;
}

if (!Loader::includeModule('as.onec_api')) {
    http_response_code(500);
    echo json_encode(
        [
            'ok' => false,
            'error' => 'MODULE',
            'message' => 'Модуль as.onec_api не установлен.',
            'api_version' => '1',
        ],
        JSON_UNESCAPED_UNICODE
    );

    return;
}

$_GET['path'] = '/v1/stocks/import';

$kernel = new \As\OnecApi\Http\JsonApiKernel();
$kernel->dispatch();
