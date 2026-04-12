<?php

namespace As\OnecApi\Catalog;

use As\OnecApi\Http\JsonResponse;
use As\OnecApi\Stock\StockEngineBootstrap;
use Bitrix\Main\Loader;

/**
 * Общие шаги для GET read API по xml_id: валидация, модули, ленивый движок, поиск элемента каталога.
 */
final class CatalogReadPreflight
{
    /**
     * @return array{ok:true, xml_id:string, element_id:int}|array{ok:false, http_code:int, data:array}
     */
    public static function forReadByXmlId(string $xmlId): array
    {
        $xmlId = trim($xmlId);
        if ($xmlId === '') {
            return [
                'ok' => false,
                'http_code' => 400,
                'data' => [
                    'ok' => false,
                    'error' => 'BAD_REQUEST',
                    'message' => 'Укажите параметр xml_id.',
                    'api_version' => JsonResponse::API_VERSION,
                ],
            ];
        }

        if (!Loader::includeModule('catalog') || !Loader::includeModule('iblock')) {
            return [
                'ok' => false,
                'http_code' => 500,
                'data' => [
                    'ok' => false,
                    'error' => 'MODULES',
                    'message' => 'Не подключены модули catalog или iblock.',
                    'api_version' => JsonResponse::API_VERSION,
                ],
            ];
        }

        StockEngineBootstrap::ensureLoaded();

        $catalogIblockIds = asStockImportFrom1cGetCatalogIblockIds();
        $elementId = asStockImportFrom1cResolveElementIdByXml($xmlId, $catalogIblockIds);
        if ($elementId <= 0) {
            return [
                'ok' => false,
                'http_code' => 404,
                'data' => [
                    'ok' => false,
                    'error' => 'PRODUCT_NOT_FOUND',
                    'message' => 'Элемент с указанным XML_ID не найден в каталоге.',
                    'api_version' => JsonResponse::API_VERSION,
                    'product_xml_id' => $xmlId,
                ],
            ];
        }

        return [
            'ok' => true,
            'xml_id' => $xmlId,
            'element_id' => $elementId,
        ];
    }

    /**
     * @return array{http_code:int, data:array}
     */
    public static function notCatalogProduct(string $xmlId, int $elementId): array
    {
        return [
            'http_code' => 422,
            'data' => [
                'ok' => false,
                'error' => 'NOT_CATALOG_PRODUCT',
                'message' => 'Нет торговой позиции catalog для этого ID.',
                'api_version' => JsonResponse::API_VERSION,
                'product_xml_id' => $xmlId,
                'element_id' => $elementId,
            ],
        ];
    }
}
