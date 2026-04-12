<?php

namespace As\OnecApi\Stock;

use Bitrix\Catalog\Config\State;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;
use Bitrix\Main\Loader;

/**
 * Чтение остатков по XML_ID элемента каталога/ТП (та же логика поиска, что у импорта).
 */
final class StockReadService
{
    private const API_VERSION = '1';

    /**
     * @return array{http_code:int, data:array}
     */
    public static function queryByXmlId(string $xmlId): array
    {
        $xmlId = trim($xmlId);
        if ($xmlId === '') {
            return [
                'http_code' => 400,
                'data' => [
                    'ok' => false,
                    'error' => 'BAD_REQUEST',
                    'message' => 'Укажите параметр xml_id.',
                    'api_version' => self::API_VERSION,
                ],
            ];
        }

        if (!Loader::includeModule('catalog') || !Loader::includeModule('iblock')) {
            return [
                'http_code' => 500,
                'data' => [
                    'ok' => false,
                    'error' => 'MODULES',
                    'message' => 'Не подключены модули catalog или iblock.',
                    'api_version' => self::API_VERSION,
                ],
            ];
        }

        StockEngineBootstrap::ensureLoaded();

        $catalogIblockIds = asStockImportFrom1cGetCatalogIblockIds();
        $elementId = asStockImportFrom1cResolveElementIdByXml($xmlId, $catalogIblockIds);
        if ($elementId <= 0) {
            return [
                'http_code' => 404,
                'data' => [
                    'ok' => false,
                    'error' => 'PRODUCT_NOT_FOUND',
                    'message' => 'Элемент с указанным XML_ID не найден в каталоге.',
                    'api_version' => self::API_VERSION,
                    'product_xml_id' => $xmlId,
                ],
            ];
        }

        $productRow = ProductTable::getList([
            'filter' => ['=ID' => $elementId],
            'select' => ['ID', 'QUANTITY'],
            'limit' => 1,
        ])->fetch();

        if (!$productRow) {
            return [
                'http_code' => 422,
                'data' => [
                    'ok' => false,
                    'error' => 'NOT_CATALOG_PRODUCT',
                    'message' => 'Нет торговой позиции catalog для этого ID.',
                    'api_version' => self::API_VERSION,
                    'product_xml_id' => $xmlId,
                    'element_id' => $elementId,
                ],
            ];
        }

        $useStores = State::isUsedInventoryManagement();
        $payload = [
            'ok' => true,
            'api_version' => self::API_VERSION,
            'product_xml_id' => $xmlId,
            'element_id' => $elementId,
            'use_store_control' => $useStores,
            'inventory_management' => $useStores,
        ];

        if ($useStores) {
            $storeRows = [];
            $rs = StoreProductTable::getList([
                'filter' => ['=PRODUCT_ID' => $elementId],
                'select' => ['STORE_ID', 'AMOUNT'],
            ]);
            while ($row = $rs->fetch()) {
                $storeRows[] = [
                    'store_id' => (int) $row['STORE_ID'],
                    'amount' => (float) $row['AMOUNT'],
                ];
            }

            $storeIds = array_values(array_unique(array_column($storeRows, 'store_id')));
            $storeMap = [];
            if ($storeIds !== []) {
                $sr = StoreTable::getList([
                    'filter' => ['@ID' => $storeIds],
                    'select' => ['ID', 'TITLE', 'XML_ID', 'CODE', 'ACTIVE'],
                ]);
                while ($s = $sr->fetch()) {
                    $storeMap[(int) $s['ID']] = $s;
                }
            }

            $stores = [];
            $total = 0.0;
            foreach ($storeRows as $sp) {
                $sid = $sp['store_id'];
                $info = $storeMap[$sid] ?? null;
                $amt = $sp['amount'];
                $total += $amt;
                $stores[] = [
                    'store_id' => $sid,
                    'amount' => $amt,
                    'store_xml_id' => $info ? (string) $info['XML_ID'] : '',
                    'store_code' => $info ? (string) $info['CODE'] : '',
                    'title' => $info ? (string) $info['TITLE'] : '',
                    'active' => $info ? ($info['ACTIVE'] === 'Y') : false,
                ];
            }

            $payload['stores'] = $stores;
            $payload['quantity_total'] = $total;
        } else {
            $payload['quantity'] = (float) $productRow['QUANTITY'];
        }

        return [
            'http_code' => 200,
            'data' => $payload,
        ];
    }
}
