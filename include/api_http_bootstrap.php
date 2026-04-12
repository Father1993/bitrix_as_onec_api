<?php

/**
 * Общая инициализация JSON API после prolog (константы STOP_STATISTICS и т.д. задаются в local/tools/*.php).
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Loader;

if (!Loader::includeModule('as.onec_api')) {
    header('Content-Type: application/json; charset=UTF-8');
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

$kernel = new \As\OnecApi\Http\JsonApiKernel();
$kernel->dispatch();
