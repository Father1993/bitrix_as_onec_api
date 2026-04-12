<?php

namespace As\Onecstock\Rest;

use Bitrix\Main\Context;
use Bitrix\Rest\RestException;

/**
 * REST-метод импорта остатков из 1С.
 *
 * Основной внешний контракт для интеграций (1С и др.): входящий вебхук с методом {@see METHOD}.
 * Отдельный корневой PHP на сайте не требуется. Скрипт `public/http_stocks_import.php` в каталоге модуля —
 * только для include после prolog (внутренний тест или тонкий прокси при необходимости).
 *
 * Вызов только через вебхук с правом на scope {@see self::SCOPE} (в админке REST приложения).
 * Тело и авторизация на стороне Bitrix24/портала обеспечиваются механизмом REST; раннеру передаётся
 * уже разобранный query как payload с флагом trust_bitrix_auth.
 *
 * Метод: {@see METHOD} (POST). При превышении лимита размера тела движок отдаёт HTTP 413; в ядре
 * {@see \CRestServer} нет именованной константы для 413, поэтому в {@see httpCodeToRestStatus} используется
 * {@see self::REST_STATUS_PAYLOAD_TOO_LARGE}.
 */
final class StockImportService
{
    public const SCOPE = 'asintegration';

    public const METHOD = 'as.stock.import';

    /** Статус для RestException при 413 (в CRestServer нет константы). */
    private const REST_STATUS_PAYLOAD_TOO_LARGE = '413 Payload Too Large';

    /**
     * Фрагмент карты REST для склейки в {@see RestService::onRestServiceBuildDescription()}.
     *
     * @return array<string, array<string, array{callback: array{0:class-string, 1:string}}>>
     */
    public static function getRestDescription(): array
    {
        return [
            self::SCOPE => [
                self::METHOD => [
                    'callback' => [self::class, 'import'],
                ],
            ],
        ];
    }

    /**
     * @param array $query
     * @param int $n
     * @param \CRestServer $_server
     * @return array
     */
    public static function import($query, $n, $_server): array
    {
        if (!Context::getCurrent()->getRequest()->isPost()) {
            throw new RestException('Используйте метод POST.', 'METHOD_NOT_ALLOWED', '405 Method Not Allowed');
        }

        $payload = self::queryToPayload(is_array($query) ? $query : []);
        $result = asStockImportFrom1cRun([
            'payload' => $payload,
            'trust_bitrix_auth' => true,
        ]);

        if ($result['http_code'] !== 200) {
            $data = $result['data'];
            $msg = (string) ($data['message'] ?? 'Ошибка запроса.');
            $code = (string) ($data['error'] ?? 'ERROR');
            throw new RestException($msg, $code, self::httpCodeToRestStatus((int) $result['http_code']));
        }

        return $result['data'];
    }

    private static function httpCodeToRestStatus(int $httpCode): string
    {
        switch ($httpCode) {
            case 400:
                return \CRestServer::STATUS_WRONG_REQUEST;
            case 401:
                return \CRestServer::STATUS_UNAUTHORIZED;
            case 413:
                return self::REST_STATUS_PAYLOAD_TOO_LARGE;
            case 500:
            default:
                return \CRestServer::STATUS_INTERNAL;
        }
    }

    private static function queryToPayload(array $query): array
    {
        $itemsByIndex = [];
        $meta = [];
        foreach ($query as $k => $v) {
            if (is_int($k) || (is_string($k) && ctype_digit((string) $k))) {
                if (is_array($v)) {
                    $itemsByIndex[(int) $k] = $v;
                }
            } else {
                $meta[$k] = $v;
            }
        }
        if ($itemsByIndex !== []) {
            ksort($itemsByIndex, SORT_NUMERIC);
            $meta['items'] = array_values($itemsByIndex);

            return $meta;
        }

        return $query;
    }
}
