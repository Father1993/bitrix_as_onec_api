<?php

namespace As\OnecApi\Price;

use As\OnecApi\Catalog\CatalogReadPreflight;
use As\OnecApi\Http\JsonResponse;
use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;

/**
 * Чтение цен по XML_ID элемента каталога/ТП.
 */
final class PriceReadService
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
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();

        if (!$productRow) {
            $err = CatalogReadPreflight::notCatalogProduct($xmlId, $elementId);

            return [
                'http_code' => $err['http_code'],
                'data' => $err['data'],
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
                'select' => ['ID', 'NAME', 'BASE', 'XML_ID'],
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
                'price_type_xml_id' => $info ? (string) ($info['XML_ID'] ?? '') : '',
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
                'api_version' => JsonResponse::API_VERSION,
                'product_xml_id' => $xmlId,
                'element_id' => $elementId,
                'prices' => $prices,
            ],
        ];
    }
}
