<?php

namespace As\OnecApi\Price;

use As\OnecApi\Stock\StockEngineBootstrap;
use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Loader;

/**
 * Чтение цен по XML_ID элемента каталога/ТП.
 */
final class PriceReadService
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
            'select' => ['ID'],
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

        $priceRows = [];
        $rs = PriceTable::getList([
            'filter' => ['=PRODUCT_ID' => $elementId],
            'select' => ['ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY', 'QUANTITY_FROM', 'QUANTITY_TO'],
        ]);
        while ($row = $rs->fetch()) {
            $priceRows[] = $row;
        }

        $groupIds = array_values(array_unique(array_filter(array_map(
            static fn ($r) => (int) ($r['CATALOG_GROUP_ID'] ?? 0),
            $priceRows
        ))));

        $groupMap = [];
        if ($groupIds !== []) {
            $gr = GroupTable::getList([
                'filter' => ['@ID' => $groupIds],
                'select' => ['ID', 'NAME', 'BASE'],
            ]);
            while ($g = $gr->fetch()) {
                $groupMap[(int) $g['ID']] = $g;
            }
        }

        $prices = [];
        foreach ($priceRows as $row) {
            $gid = (int) $row['CATALOG_GROUP_ID'];
            $info = $groupMap[$gid] ?? null;
            $prices[] = [
                'price_id' => (int) $row['ID'],
                'catalog_group_id' => $gid,
                'price_type_name' => $info ? (string) $info['NAME'] : '',
                'price_type_xml_id' => '',
                'base' => $info ? ($info['BASE'] === 'Y') : false,
                'price' => (float) $row['PRICE'],
                'currency' => (string) $row['CURRENCY'],
                'quantity_from' => isset($row['QUANTITY_FROM']) ? (float) $row['QUANTITY_FROM'] : null,
                'quantity_to' => isset($row['QUANTITY_TO']) ? (float) $row['QUANTITY_TO'] : null,
            ];
        }

        return [
            'http_code' => 200,
            'data' => [
                'ok' => true,
                'api_version' => self::API_VERSION,
                'product_xml_id' => $xmlId,
                'element_id' => $elementId,
                'prices' => $prices,
            ],
        ];
    }
}
