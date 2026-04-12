<?php

namespace As\OnecApi\Stock;

use As\OnecApi\Catalog\CatalogReadPreflight;
use As\OnecApi\Http\JsonResponse;
use Bitrix\Catalog\Config\State;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;

/**
 * Чтение остатков по XML_ID элемента каталога/ТП (та же логика поиска, что у импорта).
 */
final class StockReadService
{
    /**
     * @return array{http_code:int, data:array}
     */
    public static function queryByXmlId(string $xmlId): array
    {
        $pre = CatalogReadPreflight::forReadByXmlId($xmlId);
        if (!$pre['ok']) {
            return [
                'http_code' => $pre['http_code'],
                'data' => $pre['data'],
            ];
        }

        $xmlId = $pre['xml_id'];
        $elementId = $pre['element_id'];

        $productRow = ProductTable::getList([
            'filter' => ['=ID' => $elementId],
            'select' => ['ID', 'QUANTITY'],
            'limit' => 1,
        ])->fetch();

        if (!$productRow) {
            $err = CatalogReadPreflight::notCatalogProduct($xmlId, $elementId);

            return [
                'http_code' => $err['http_code'],
                'data' => $err['data'],
            ];
        }

        $useStores = State::isUsedInventoryManagement();
        $payload = [
            'ok' => true,
            'api_version' => JsonResponse::API_VERSION,
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
