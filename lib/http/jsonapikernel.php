<?php

namespace As\OnecApi\Http;

use As\OnecApi\Price\PriceImportService;
use As\OnecApi\Price\PriceReadService;
use As\OnecApi\Product\ProductReadService;
use As\OnecApi\Stock\StockEngineBootstrap;
use As\OnecApi\Stock\StockReadService;

/**
 * Версионируемый JSON API модуля (не rest-модуль Битрикса). Маршруты: PATH_INFO или query path=.
 *
 * Перед любым обработчиком маршрута вызывается {@see StockEngineBootstrap::ensureLoaded()} — подключается
 * {@see include/stock_import_engine.php} (глобальные asStock*, авторизация импорта). Без этого шага нельзя
 * вызывать {@see asStockApiAuthBySecretKey}, {@see asStockImportFrom1cRun} и сервисы, которые опираются на эти функции.
 */
final class JsonApiKernel
{
    /**
     * @return list<array{0:string,1:string,2:callable}>
     */
    private function routes(): array
    {
        return [
            ['GET', '/v1/stocks', [$this, 'handleGetStocks']],
            ['POST', '/v1/stocks/import', [$this, 'handlePostStocksImport']],
            ['POST', '/v1/stocks', [$this, 'handlePostStocksImport']],
            ['GET', '/v1/prices', [$this, 'handleGetPrices']],
            ['POST', '/v1/prices', [$this, 'handlePostPrices']],
            ['GET', '/v1/products', [$this, 'handleGetProducts']],
        ];
    }

    public function dispatch(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = $this->resolvePath();

        foreach ($this->routes() as [$m, $p, $handler]) {
            if ($m === $method && $this->pathMatches($path, $p)) {
                StockEngineBootstrap::ensureLoaded();
                $handler();

                return;
            }
        }

        JsonResponse::send(
            404,
            [
                'ok' => false,
                'error' => 'NOT_FOUND',
                'message' => 'Неизвестный маршрут API. См. документацию: GET/POST path=/v1/stocks, /v1/stocks/import, /v1/prices, /v1/products.',
            ]
        );
    }

    private function pathMatches(string $path, string $expected): bool
    {
        $path = rtrim($path, '/') ?: '/';
        $expected = rtrim($expected, '/') ?: '/';

        return $path === $expected;
    }

    private function resolvePath(): string
    {
        $raw = '';
        if (!empty($_SERVER['PATH_INFO'])) {
            $raw = (string) $_SERVER['PATH_INFO'];
        } elseif (isset($_GET['path']) && is_string($_GET['path'])) {
            $raw = $_GET['path'];
        }

        $raw = trim(str_replace('\\', '/', $raw), '/');
        if ($raw === '') {
            $path = '/';
        } else {
            $path = '/' . $raw;
        }

        if (($path === '/' || $path === '') && defined('AS_ONEC_API_DEFAULT_PATH')) {
            return (string) AS_ONEC_API_DEFAULT_PATH;
        }

        return $path === '' ? '/' : $path;
    }

    private function handleGetStocks(): void
    {
        $queryKey = isset($_GET['access_key']) ? (string) $_GET['access_key'] : null;
        $auth = ApiKeyGuard::authorizeQueryOrHeader($queryKey);
        if (!$auth['ok']) {
            JsonResponse::send(
                401,
                [
                    'ok' => false,
                    'error' => 'UNAUTHORIZED',
                    'message' => $auth['message'] ?? 'Требуется авторизация.',
                ]
            );

            return;
        }

        $xmlId = isset($_GET['xml_id']) ? (string) $_GET['xml_id'] : '';
        $result = StockReadService::queryByXmlId($xmlId);
        JsonResponse::send($result['http_code'], $result['data']);
    }

    private function handlePostStocksImport(): void
    {
        $result = asStockImportFrom1cRun();
        JsonResponse::send($result['http_code'], JsonResponse::withApiVersion($result['data']));
    }

    private function handleGetPrices(): void
    {
        $queryKey = isset($_GET['access_key']) ? (string) $_GET['access_key'] : null;
        $auth = ApiKeyGuard::authorizeQueryOrHeader($queryKey);
        if (!$auth['ok']) {
            JsonResponse::send(
                401,
                [
                    'ok' => false,
                    'error' => 'UNAUTHORIZED',
                    'message' => $auth['message'] ?? 'Требуется авторизация.',
                ]
            );

            return;
        }

        $xmlId = isset($_GET['xml_id']) ? (string) $_GET['xml_id'] : '';
        $result = PriceReadService::queryByXmlId($xmlId);
        JsonResponse::send($result['http_code'], $result['data']);
    }

    private function handlePostPrices(): void
    {
        $result = PriceImportService::run();
        JsonResponse::send($result['http_code'], JsonResponse::withApiVersion($result['data']));
    }

    private function handleGetProducts(): void
    {
        $queryKey = isset($_GET['access_key']) ? (string) $_GET['access_key'] : null;
        $auth = ApiKeyGuard::authorizeQueryOrHeader($queryKey);
        if (!$auth['ok']) {
            JsonResponse::send(
                401,
                [
                    'ok' => false,
                    'error' => 'UNAUTHORIZED',
                    'message' => $auth['message'] ?? 'Требуется авторизация.',
                ]
            );

            return;
        }

        $xmlId = isset($_GET['xml_id']) ? (string) $_GET['xml_id'] : '';
        $result = ProductReadService::queryByXmlId($xmlId);
        JsonResponse::send($result['http_code'], $result['data']);
    }
}
