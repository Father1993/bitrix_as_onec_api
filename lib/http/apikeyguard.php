<?php

namespace As\OnecApi\Http;

use As\OnecApi\Stock\StockEngineBootstrap;

/**
 * Авторизация по секретному ключу для GET/query (обёртка над {@see asStockApiAuthBySecretKey()}).
 * Перед вызовом подключает процедурный движок остатков.
 */
final class ApiKeyGuard
{
    /**
     * @param string|null $accessKeyFromQuery значение access_key из query (не из тела)
     * @return array{ok:bool, message?:string}
     */
    public static function authorizeQueryOrHeader(?string $accessKeyFromQuery = null): array
    {
        StockEngineBootstrap::ensureLoaded();

        return asStockApiAuthBySecretKey($accessKeyFromQuery);
    }
}
