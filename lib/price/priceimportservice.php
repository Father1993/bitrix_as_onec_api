<?php

namespace As\OnecApi\Price;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use As\OnecApi\Stock\StockEngineBootstrap;
use As\OnecApi\StockImportOptions;

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
        StockEngineBootstrap::ensureLoaded();

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
            return [
                'http_code' => 413,
                'data' => [
                    'ok' => false,
                    'error' => 'PAYLOAD_TOO_LARGE',
                    'message' => 'Превышен размер тела запроса.',
                    'max_bytes' => $maxBodyBytes,
                ],
            ];
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

        $items = self::normalizeItems($decoded);
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
                    $r = self::applyRow($row, $catalogIblockIds);
                    if ($r['ok']) {
                        $summary['updated']++;
                    } else {
                        $summary['failed']++;
                        if (count($summary['errors']) < 200) {
                            $summary['errors'][] = [
                                'index' => $globalIndex,
                                'product_xml_id' => $row['product_xml_id'] ?? null,
                                'product_id' => $row['product_id'] ?? null,
                                'catalog_group_id' => $row['catalog_group_id'] ?? null,
                                'message' => $r['message'],
                            ];
                        }
                    }
                }

                $connection->commitTransaction();
            } catch (\Throwable $e) {
                $connection->rollbackTransaction();

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
        if (is_array($decoded) && array_keys($decoded) !== range(0, count($decoded) - 1)) {
            if (!isset($decoded['items']) || !is_array($decoded['items'])) {
                return null;
            }
            $items = $decoded['items'];
        } elseif (is_array($decoded)) {
            $items = $decoded;
        } else {
            return null;
        }

        if ($items === []) {
            return null;
        }

        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }

            $xml =
                $row['product_xml_id']
                ?? $row['xml_id']
                ?? $row['XML_ID']
                ?? '';
            $xml = is_string($xml) ? trim($xml) : '';

            $pid = isset($row['product_id']) ? (int) $row['product_id'] : 0;

            $gid = $row['catalog_group_id'] ?? $row['price_type_id'] ?? $row['CATALOG_GROUP_ID'] ?? null;
            $gid = is_numeric($gid) ? (int) $gid : 0;

            $price = $row['price'] ?? null;
            if ($price === null || !is_numeric($price)) {
                continue;
            }
            $price = (float) $price;
            if ($price < 0) {
                continue;
            }

            $currency = isset($row['currency']) ? trim((string) $row['currency']) : '';
            if ($currency === '') {
                $currency = 'RUB';
            }

            if ($pid <= 0 && $xml === '') {
                continue;
            }
            if ($gid <= 0) {
                continue;
            }

            $out[] = [
                'product_xml_id' => $xml,
                'product_id' => $pid,
                'catalog_group_id' => $gid,
                'price' => $price,
                'currency' => $currency,
            ];
        }

        return $out === [] ? null : $out;
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
            $resolved = asStockImportFrom1cResolveElementIdByXml($xml, $catalogIblockIds);
            if ($resolved <= 0) {
                return ['ok' => false, 'message' => 'Элемент с указанным XML_ID не найден в каталоге.'];
            }
            $productId = $resolved;
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
