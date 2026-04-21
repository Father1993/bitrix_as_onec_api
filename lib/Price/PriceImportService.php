<?php

namespace As\OnecApi\Price;

use As\OnecApi\Http\JsonResponse;
use As\OnecApi\Stock\StockEngineBootstrap;
use As\OnecApi\StockImportOptions;
use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;

/**
 * Импорт цен (POST JSON): items с product_xml_id, catalog_group_id, price, currency.
 */
final class PriceImportService
{
    /**
     * @return array{http_code:int, data:array}
     */
    public static function run(): array
    {
        if (!Loader::includeModule('catalog') || !Loader::includeModule('iblock')) {
            return [
                'http_code' => 500,
                'data' => [
                    'ok' => false,
                    'error' => 'MODULES',
                    'message' => 'Не подключены модули catalog или iblock.',
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
                    'message' => 'Ожидается непустой массив items или корневой JSON-массив цен.',
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

        $catalogIblockIds = asStockImportFrom1cGetCatalogIblockIds();

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
                            $summary['errors'][] = [
                                'index' => $globalIndex,
                                'product_xml_id' => is_array($row) ? ($row['product_xml_id'] ?? $row['xml_id'] ?? $row['XML_ID'] ?? null) : null,
                                'product_id' => is_array($row) ? ($row['product_id'] ?? null) : null,
                                'catalog_group_id' => is_array($row) ? ($row['catalog_group_id'] ?? $row['price_type_id'] ?? $row['CATALOG_GROUP_ID'] ?? null) : null,
                                'message' => $validated['message'],
                            ];
                        }
                        continue;
                    }

                    $normalizedRow = $validated['row'];
                    $r = self::applyRow($normalizedRow, $catalogIblockIds);
                    if ($r['ok']) {
                        $summary['updated']++;
                    } else {
                        $summary['failed']++;
                        if (count($summary['errors']) < 200) {
                            $summary['errors'][] = [
                                'index' => $globalIndex,
                                'product_xml_id' => $normalizedRow['product_xml_id'] ?? null,
                                'product_id' => $normalizedRow['product_id'] ?? null,
                                'catalog_group_id' => $normalizedRow['catalog_group_id'] ?? null,
                                'message' => $r['message'],
                            ];
                        }
                    }
                }

                $connection->commitTransaction();
            } catch (\Throwable $e) {
                $connection->rollbackTransaction();
                asStockImportFrom1cLog('price_batch_exception', [
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

        \Bitrix\Catalog\Model\Product::clearCache();

        if ($summary['failed'] > count($summary['errors'])) {
            $summary['errors_truncated'] = true;
        }

        asStockImportFrom1cLog('price_import_done', [
            'updated' => $summary['updated'],
            'failed' => $summary['failed'],
        ]);

        return [
            'http_code' => 200,
            'data' => $summary,
        ];
    }

    /**
     * @param mixed $decoded
     * @return list<array<string, mixed>>|null
     */
    private static function normalizeItems($decoded): ?array
    {
        $items = asStockImportFrom1cExtractItems($decoded);
        if ($items === null) {
            return null;
        }

        $out = [];
        foreach ($items as $row) {
            $validated = self::validateItem($row);
            if (!$validated['ok']) {
                continue;
            }

            $out[] = $validated['row'];
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param mixed $row
     * @return array{ok:true,row:array<string,mixed>}|array{ok:false,message:string}
     */
    private static function validateItem($row): array
    {
        if (!is_array($row)) {
            return ['ok' => false, 'message' => 'Позиция должна быть объектом JSON.'];
        }

        $xml =
            $row['product_xml_id']
            ?? $row['xml_id']
            ?? $row['XML_ID']
            ?? '';
        $xml = is_string($xml) ? trim($xml) : '';

        $pid = 0;
        if (array_key_exists('product_id', $row) && $row['product_id'] !== '' && $row['product_id'] !== null) {
            if (!is_numeric($row['product_id'])) {
                return ['ok' => false, 'message' => 'Поле product_id должно быть числом.'];
            }
            $pid = (int) $row['product_id'];
            if ($pid <= 0) {
                return ['ok' => false, 'message' => 'Поле product_id должно быть положительным числом.'];
            }
        }

        $gid = $row['catalog_group_id'] ?? $row['price_type_id'] ?? $row['CATALOG_GROUP_ID'] ?? null;
        if ($gid === null || $gid === '') {
            return ['ok' => false, 'message' => 'Поле catalog_group_id обязательно.'];
        }
        if (!is_numeric($gid)) {
            return ['ok' => false, 'message' => 'Поле catalog_group_id должно быть числом.'];
        }
        $gid = (int) $gid;
        if ($gid <= 0) {
            return ['ok' => false, 'message' => 'Поле catalog_group_id должно быть положительным числом.'];
        }

        $price = $row['price'] ?? null;
        if ($price === null || $price === '') {
            return ['ok' => false, 'message' => 'Поле price обязательно.'];
        }
        if (!is_numeric($price)) {
            return ['ok' => false, 'message' => 'Поле price должно быть числом.'];
        }
        $price = (float) $price;
        if ($price < 0) {
            return ['ok' => false, 'message' => 'Поле price не может быть отрицательным.'];
        }

        $currency = isset($row['currency']) ? trim((string) $row['currency']) : '';
        if ($currency === '') {
            $currency = 'RUB';
        }

        if ($pid <= 0 && $xml === '') {
            return ['ok' => false, 'message' => 'Нужен product_id или product_xml_id/xml_id/XML_ID.'];
        }

        return [
            'ok' => true,
            'row' => [
                'product_xml_id' => $xml,
                'product_id' => $pid,
                'catalog_group_id' => $gid,
                'price' => $price,
                'currency' => $currency,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param int[] $catalogIblockIds
     * @return array{ok:bool, message?:string}
     */
    private static function applyRow(array $row, array $catalogIblockIds): array
    {
        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId <= 0) {
            $xml = (string) ($row['product_xml_id'] ?? '');
            $resolved = asStockImportFrom1cResolveElementByXml($xml, $catalogIblockIds);
            if (!$resolved['ok']) {
                return ['ok' => false, 'message' => $resolved['message']];
            }
            $productId = $resolved['id'];
        }

        $productRow = ProductTable::getList([
            'filter' => ['=ID' => $productId],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();
        if (!$productRow) {
            return ['ok' => false, 'message' => 'Нет торговой позиции catalog для этого ID.'];
        }

        $groupId = (int) ($row['catalog_group_id'] ?? 0);
        $group = GroupTable::getList([
            'filter' => ['=ID' => $groupId],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();
        if (!$group) {
            return ['ok' => false, 'message' => 'Тип цены не найден (catalog_group_id).'];
        }

        $existing = PriceTable::getList([
            'filter' => [
                '=PRODUCT_ID' => $productId,
                '=CATALOG_GROUP_ID' => $groupId,
            ],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();

        $fields = [
            'PRODUCT_ID' => $productId,
            'CATALOG_GROUP_ID' => $groupId,
            'PRICE' => $row['price'],
            'CURRENCY' => $row['currency'],
        ];

        if ($existing) {
            $res = PriceTable::update((int) $existing['ID'], $fields);
        } else {
            $res = PriceTable::add($fields);
        }

        if (!$res->isSuccess()) {
            return [
                'ok' => false,
                'message' => 'Не удалось записать цену: ' . implode('; ', $res->getErrorMessages()),
            ];
        }

        return ['ok' => true];
    }
}
