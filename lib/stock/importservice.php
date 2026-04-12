<?php

namespace As\OnecApi\Stock;

use Bitrix\Catalog\Config\State;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use As\OnecApi\StockImportOptions;

/**
 * Импорт остатков из JSON (логика {@see asStockImportFrom1cRun}).
 */
final class ImportService
{
    /**
     * @param array{payload?:array, trust_bitrix_auth?:bool} $options
     * @return array{http_code:int, data:array}
     */
    public static function run(array $options = []): array
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

        $trustBitrixAuth = !empty($options['trust_bitrix_auth']);
        $maxBodyBytes = StockImportOptions::getMaxBodyBytes();
        $maxItems = StockImportOptions::getMaxItems();
        $batchSize = StockImportOptions::getBatchSize();

        if (array_key_exists('payload', $options) && is_array($options['payload'])) {
            $decoded = $options['payload'];
            $len = strlen(json_encode($decoded, JSON_UNESCAPED_UNICODE));
            if ($len > $maxBodyBytes) {
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
        } else {
            $raw = file_get_contents('php://input');
            if ($raw === false) {
                return [
                    'http_code' => 400,
                    'data' => ['ok' => false, 'error' => 'EMPTY_BODY', 'message' => 'Пустое тело запроса.'],
                ];
            }

            $len = strlen($raw);
            if ($len > $maxBodyBytes) {
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
        }

        if (!$trustBitrixAuth) {
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
        }

        $items = asStockImportFrom1cNormalizeItems($decoded);
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

        $useStores = State::isUsedInventoryManagement();
        $catalogIblockIds = asStockImportFrom1cGetCatalogIblockIds();

        $summary = [
            'ok' => true,
            'inventory_management' => $useStores,
            'total' => count($items),
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $batches = array_chunk($items, $batchSize);
        $connection = Application::getConnection();

        foreach ($batches as $batchIndex => $batch) {
            $productIdsForRecalc = [];

            try {
                $connection->startTransaction();

                foreach ($batch as $idx => $row) {
                    $globalIndex = $batchIndex * $batchSize + $idx;
                    $r = asStockImportFrom1cApplyRow($row, $useStores, $catalogIblockIds);
                    if ($r['ok']) {
                        $summary['updated']++;
                        if (!empty($r['product_id'])) {
                            $productIdsForRecalc[$r['product_id']] = true;
                        }
                    } else {
                        $summary['failed']++;
                        if (count($summary['errors']) < 200) {
                            $summary['errors'][] = [
                                'index' => $globalIndex,
                                'product_xml_id' => $row['product_xml_id'] ?? null,
                                'product_id' => $row['product_id'] ?? null,
                                'message' => $r['message'],
                            ];
                        }
                    }
                }

                $connection->commitTransaction();

                if ($useStores && $productIdsForRecalc !== []) {
                    /**
                     * @todo Заменить на проверенный D7-аналог при появлении в ядре и тестах паритета агрегированных остатков.
                     */
                    \CCatalogStore::recalculateProductsBalances(array_map('intval', array_keys($productIdsForRecalc)));
                }
            } catch (\Throwable $e) {
                $connection->rollbackTransaction();
                asStockImportFrom1cLog('batch_exception', [
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

        if (!$useStores) {
            \Bitrix\Catalog\Model\Product::clearCache();
        }

        if ($summary['failed'] > count($summary['errors'])) {
            $summary['errors_truncated'] = true;
        }

        asStockImportFrom1cLog('import_done', [
            'updated' => $summary['updated'],
            'failed' => $summary['failed'],
            'inventory' => $useStores,
        ]);

        return [
            'http_code' => 200,
            'data' => $summary,
        ];
    }
}
