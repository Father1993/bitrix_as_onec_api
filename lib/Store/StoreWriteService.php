<?php

namespace As\OnecApi\Store;

use As\OnecApi\Http\JsonResponse;
use As\OnecApi\Stock\StockEngineBootstrap;
use As\OnecApi\StockImportOptions;
use Bitrix\Catalog\StoreTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;

/**
 * Создание/обновление складов (POST JSON): пакет items, как импорт цен/остатков.
 */
final class StoreWriteService
{
    private const DEFAULT_TITLE = 'Склад';
    private const DEFAULT_ADDRESS = '-';

    /**
     * @return array{http_code:int, data:array}
     */
    public static function run(): array
    {
        if (!Loader::includeModule('catalog')) {
            return [
                'http_code' => 500,
                'data' => [
                    'ok' => false,
                    'error' => 'MODULES',
                    'message' => 'Не подключён модуль catalog.',
                ],
            ];
        }

        StockEngineBootstrap::ensureLoaded();

        $maxBodyBytes = StockImportOptions::getMaxBodyBytes();
        $maxItems = StockImportOptions::getMaxItems();
        $batchSize = StockImportOptions::getBatchSize();

        $raw = file_get_contents('php://input');
        if ($raw === false) {
            return [
                'http_code' => 400,
                'data' => ['ok' => false, 'error' => 'EMPTY_BODY', 'message' => 'Пустое тело запроса.'],
            ];
        }

        if (strlen($raw) > $maxBodyBytes) {
            return JsonResponse::payloadTooLarge($maxBodyBytes);
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'http_code' => 400,
                'data' => [
                    'ok' => false,
                    'error' => 'INVALID_JSON',
                    'message' => 'Некорректный JSON.',
                ],
            ];
        }

        $auth = asStockImportFrom1cAuth($decoded);
        if (!$auth['ok']) {
            return [
                'http_code' => 401,
                'data' => [
                    'ok' => false,
                    'error' => 'AUTH',
                    'message' => $auth['message'] ?? 'Ошибка авторизации.',
                ],
            ];
        }

        $items = asStockImportFrom1cExtractItems($decoded);
        if ($items === null) {
            return [
                'http_code' => 400,
                'data' => [
                    'ok' => false,
                    'error' => 'INVALID_ITEMS',
                    'message' => 'Ожидается непустой массив items или корневой JSON-массив позиций.',
                ],
            ];
        }

        if (count($items) > $maxItems) {
            return [
                'http_code' => 400,
                'data' => [
                    'ok' => false,
                    'error' => 'TOO_MANY_ITEMS',
                    'message' => 'Слишком много позиций в запросе.',
                    'max_items' => $maxItems,
                ],
            ];
        }

        $summary = [
            'ok' => true,
            'total' => count($items),
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $batches = array_chunk($items, $batchSize);
        $connection = Application::getConnection();

        foreach ($batches as $batchIndex => $batch) {
            try {
                $connection->startTransaction();

                foreach ($batch as $idx => $row) {
                    $globalIndex = $batchIndex * $batchSize + $idx;
                    $validated = self::validateItem($row);
                    if (!$validated['ok']) {
                        $summary['failed']++;
                        if (count($summary['errors']) < 200) {
                            $summary['errors'][] = self::errorEntry(
                                $globalIndex,
                                is_array($row) ? $row : [],
                                $validated['message']
                            );
                        }
                        continue;
                    }

                    $r = self::applyRow($validated['row']);
                    if ($r['ok']) {
                        $summary['updated']++;
                    } else {
                        $summary['failed']++;
                        if (count($summary['errors']) < 200) {
                            $summary['errors'][] = self::errorEntry(
                                $globalIndex,
                                $validated['row'],
                                $r['message'] ?? 'Ошибка записи.'
                            );
                        }
                    }
                }

                $connection->commitTransaction();
            } catch (\Throwable $e) {
                $connection->rollbackTransaction();
                asStockImportFrom1cLog('store_batch_exception', [
                    'batch' => $batchIndex,
                    'message' => $e->getMessage(),
                ]);

                return [
                    'http_code' => 500,
                    'data' => [
                        'ok' => false,
                        'error' => 'BATCH_FAILED',
                        'message' => 'Ошибка при обработке пакета.',
                        'batch_index' => $batchIndex,
                    ],
                ];
            }
        }

        if ($summary['failed'] > count($summary['errors'])) {
            $summary['errors_truncated'] = true;
        }

        asStockImportFrom1cLog('store_write_done', [
            'updated' => $summary['updated'],
            'failed' => $summary['failed'],
        ]);

        return [
            'http_code' => 200,
            'data' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ok:true, row: array<string, mixed>}|array{ok:false, message:string}
     */
    private static function validateItem($row): array
    {
        if (!is_array($row)) {
            return ['ok' => false, 'message' => 'Позиция должна быть объектом JSON.'];
        }

        $storeId = 0;
        if (array_key_exists('store_id', $row) && $row['store_id'] !== '' && $row['store_id'] !== null) {
            if (!is_numeric($row['store_id'])) {
                return ['ok' => false, 'message' => 'Поле store_id должно быть числом.'];
            }
            $storeId = (int) $row['store_id'];
            if ($storeId < 0) {
                return ['ok' => false, 'message' => 'Поле store_id не может быть отрицательным.'];
            }
        }

        $xml = self::str($row, ['store_xml_id', 'xml_id', 'XML_ID']);
        $code = self::str($row, ['store_code', 'code', 'CODE']);

        if ($storeId === 0 && $xml === '' && $code === '') {
            return ['ok' => false, 'message' => 'Укажите store_id для обновления или store_xml_id / code для поиска, либо передайте store_xml_id или code при создании нового склада.'];
        }

        return [
            'ok' => true,
            'row' => [
                'raw' => $row,
                'store_id' => $storeId,
                'store_xml_id' => $xml,
                'store_code' => $code,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array{ok:bool, message?:string}
     */
    private static function applyRow(array $normalized): array
    {
        $raw = $normalized['raw'];
        if (!is_array($raw)) {
            return ['ok' => false, 'message' => 'Некорректная позиция.'];
        }

        $storeId = (int) ($normalized['store_id'] ?? 0);
        $xml = (string) ($normalized['store_xml_id'] ?? '');
        $code = (string) ($normalized['store_code'] ?? '');

        $targetId = 0;
        if ($storeId > 0) {
            $exists = StoreTable::getList([
                'filter' => ['=ID' => $storeId],
                'select' => ['ID'],
                'limit' => 1,
            ])->fetch();
            if (!$exists) {
                return ['ok' => false, 'message' => 'Склад с указанным store_id не найден.'];
            }
            $targetId = $storeId;
        } else {
            $resolved = self::resolveStoreIdByXmlOrCode($xml, $code);
            if (!$resolved['ok']) {
                return ['ok' => false, 'message' => $resolved['message']];
            }
            $targetId = (int) ($resolved['id'] ?? 0);
        }

        $fields = self::mapInputToOrmFields($raw);
        if ($fields === null) {
            $fields = [];
        }

        if ($targetId > 0) {
            if ($fields === []) {
                return ['ok' => false, 'message' => 'Нет полей для обновления: передайте хотя бы одно из полей (title, address, active, …).'];
            }

            $res = StoreTable::update($targetId, $fields);
            if (!$res->isSuccess()) {
                return ['ok' => false, 'message' => implode('; ', $res->getErrorMessages())];
            }

            return ['ok' => true];
        }

        $add = self::fieldsForAdd($fields, $xml, $code);
        $res = StoreTable::add($add);
        if (!$res->isSuccess()) {
            return ['ok' => false, 'message' => implode('; ', $res->getErrorMessages())];
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok:true, id:int}|array{ok:false, message:string}
     */
    private static function resolveStoreIdByXmlOrCode(string $xml, string $code): array
    {
        if ($xml !== '') {
            $rows = StoreTable::getList([
                'filter' => ['=XML_ID' => $xml],
                'select' => ['ID'],
                'order' => ['ID' => 'ASC'],
                'limit' => 2,
            ])->fetchAll();
            if (count($rows) > 1) {
                return ['ok' => false, 'message' => 'Найдено несколько складов с одинаковым store_xml_id.'];
            }
            if (count($rows) === 1) {
                return ['ok' => true, 'id' => (int) $rows[0]['ID']];
            }
        }

        if ($code !== '') {
            $rows = StoreTable::getList([
                'filter' => ['=CODE' => $code],
                'select' => ['ID'],
                'order' => ['ID' => 'ASC'],
                'limit' => 2,
            ])->fetchAll();
            if (count($rows) > 1) {
                return ['ok' => false, 'message' => 'Найдено несколько складов с одинаковым code.'];
            }
            if (count($rows) === 1) {
                return ['ok' => true, 'id' => (int) $rows[0]['ID']];
            }
        }

        if ($xml === '' && $code === '') {
            return ['ok' => false, 'message' => 'Для создания укажите store_xml_id или code.'];
        }

        return ['ok' => true, 'id' => 0];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private static function str(array $row, array $keys): string
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $v = $row[$k];
            if ($v === null) {
                continue;
            }
            $s = is_string($v) ? trim($v) : trim((string) $v);

            return $s;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null null если нет ни одного известного ключа
     */
    private static function mapInputToOrmFields(array $raw): ?array
    {
        $map = [
            'title' => 'TITLE',
            'address' => 'ADDRESS',
            'description' => 'DESCRIPTION',
            'store_xml_id' => 'XML_ID',
            'xml_id' => 'XML_ID',
            'XML_ID' => 'XML_ID',
            'store_code' => 'CODE',
            'code' => 'CODE',
            'CODE' => 'CODE',
            'sort' => 'SORT',
            'site_id' => 'SITE_ID',
            'phone' => 'PHONE',
            'email' => 'EMAIL',
            'schedule' => 'SCHEDULE',
            'gps_n' => 'GPS_N',
            'gps_s' => 'GPS_S',
            'location_id' => 'LOCATION_ID',
            'image_id' => 'IMAGE_ID',
        ];

        $out = [];
        foreach ($map as $jsonKey => $ormKey) {
            if (!array_key_exists($jsonKey, $raw)) {
                continue;
            }
            $val = $raw[$jsonKey];
            if ($jsonKey === 'sort' || $jsonKey === 'location_id') {
                if ($val === '' || $val === null) {
                    continue;
                }
                if (!is_numeric($val)) {
                    continue;
                }
                $out[$ormKey] = (int) $val;
                continue;
            }
            if ($jsonKey === 'store_xml_id' || $jsonKey === 'xml_id' || $jsonKey === 'XML_ID') {
                $out['XML_ID'] = is_string($val) ? trim($val) : trim((string) $val);
                continue;
            }
            if ($jsonKey === 'store_code' || $jsonKey === 'code' || $jsonKey === 'CODE') {
                $out['CODE'] = is_string($val) ? trim($val) : trim((string) $val);
                continue;
            }
            $out[$ormKey] = is_string($val) ? $val : (string) $val;
        }

        if (array_key_exists('active', $raw)) {
            $out['ACTIVE'] = self::toYN($raw['active']) ? 'Y' : 'N';
        }
        if (array_key_exists('issuing_center', $raw)) {
            $out['ISSUING_CENTER'] = self::toYN($raw['issuing_center']) ? 'Y' : 'N';
        }
        if (array_key_exists('shipping_center', $raw)) {
            $out['SHIPPING_CENTER'] = self::toYN($raw['shipping_center']) ? 'Y' : 'N';
        }

        if ($out === []) {
            return null;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $fields partial from mapInputToOrmFields
     * @return array<string, mixed>
     */
    private static function fieldsForAdd(array $fields, string $xml, string $code): array
    {
        $defaults = [
            'ACTIVE' => 'Y',
            'ISSUING_CENTER' => 'N',
            'SHIPPING_CENTER' => 'N',
            'SORT' => 100,
            'TITLE' => self::DEFAULT_TITLE,
            'ADDRESS' => self::DEFAULT_ADDRESS,
            'XML_ID' => $xml,
            'CODE' => $code,
        ];

        $merged = array_merge($defaults, $fields);
        if ($xml !== '' && !array_key_exists('XML_ID', $fields)) {
            $merged['XML_ID'] = $xml;
        }
        if ($code !== '' && !array_key_exists('CODE', $fields)) {
            $merged['CODE'] = $code;
        }
        if (!isset($merged['TITLE']) || $merged['TITLE'] === '') {
            $merged['TITLE'] = self::DEFAULT_TITLE;
        }
        if (!isset($merged['ADDRESS']) || $merged['ADDRESS'] === '') {
            $merged['ADDRESS'] = self::DEFAULT_ADDRESS;
        }

        return $merged;
    }

    /**
     * @param mixed $v
     */
    private static function toYN($v): bool
    {
        if ($v === true || $v === 1) {
            return true;
        }
        if ($v === false || $v === 0) {
            return false;
        }
        if (is_string($v)) {
            $s = strtoupper(trim($v));

            return $s === 'Y' || $s === '1' || $s === 'TRUE' || $s === 'YES';
        }

        return (bool) $v;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function errorEntry(int $index, array $row, string $message): array
    {
        return [
            'index' => $index,
            'store_id' => $row['store_id'] ?? null,
            'store_xml_id' => $row['store_xml_id'] ?? $row['xml_id'] ?? null,
            'message' => $message,
        ];
    }
}
