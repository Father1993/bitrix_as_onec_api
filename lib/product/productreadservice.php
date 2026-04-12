<?php

namespace As\OnecApi\Product;

use As\OnecApi\Stock\StockEngineBootstrap;
use Bitrix\Catalog\ProductTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;

/**
 * Чтение карточки товара/ТП по XML_ID (элемент ИБ + базовые поля catalog product).
 */
final class ProductReadService
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

        $el = ElementTable::getList([
            'filter' => ['=ID' => $elementId],
            'select' => ['ID', 'IBLOCK_ID', 'NAME', 'XML_ID', 'CODE', 'ACTIVE', 'SORT', 'PREVIEW_TEXT'],
            'limit' => 1,
        ])->fetch();

        if (!$el) {
            return [
                'http_code' => 404,
                'data' => [
                    'ok' => false,
                    'error' => 'PRODUCT_NOT_FOUND',
                    'message' => 'Элемент не найден.',
                    'api_version' => self::API_VERSION,
                    'product_xml_id' => $xmlId,
                ],
            ];
        }

        $productRow = ProductTable::getList([
            'filter' => ['=ID' => $elementId],
            'select' => [
                'ID',
                'QUANTITY',
                'QUANTITY_TRACE',
                'TYPE',
                'AVAILABLE',
                'MEASURE',
            ],
            'limit' => 1,
        ])->fetch();

        $payload = [
            'ok' => true,
            'api_version' => self::API_VERSION,
            'product_xml_id' => $xmlId,
            'element' => [
                'id' => (int) $el['ID'],
                'iblock_id' => (int) $el['IBLOCK_ID'],
                'name' => (string) $el['NAME'],
                'xml_id' => (string) $el['XML_ID'],
                'code' => (string) ($el['CODE'] ?? ''),
                'active' => ($el['ACTIVE'] ?? '') === 'Y',
                'sort' => (int) ($el['SORT'] ?? 0),
                'preview_text' => (string) ($el['PREVIEW_TEXT'] ?? ''),
            ],
            'catalog_product' => null,
        ];

        if ($productRow) {
            $payload['catalog_product'] = [
                'id' => (int) $productRow['ID'],
                'quantity' => (float) ($productRow['QUANTITY'] ?? 0),
                'quantity_trace' => (string) ($productRow['QUANTITY_TRACE'] ?? ''),
                'type' => (int) ($productRow['TYPE'] ?? 0),
                'available' => (string) ($productRow['AVAILABLE'] ?? ''),
                'measure' => isset($productRow['MEASURE']) ? (int) $productRow['MEASURE'] : null,
            ];
        }

        return [
            'http_code' => 200,
            'data' => $payload,
        ];
    }
}
