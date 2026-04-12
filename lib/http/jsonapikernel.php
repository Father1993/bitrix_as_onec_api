<?php

namespace As\Onecstock\Http;

use As\Onecstock\Stock\StockReadService;

/**
 * Версионируемый JSON API модуля (не rest-модуль Битрикса). Маршруты: PATH_INFO или query path=.
 */
final class JsonApiKernel
{
    private const API_VERSION = '1';

    public function dispatch(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = $this->resolvePath();

        if ($method === 'GET' && $this->pathMatches($path, '/v1/stocks')) {
            $this->handleGetStocks();

            return;
        }

        http_response_code(404);
        echo json_encode(
            [
                'ok' => false,
                'error' => 'NOT_FOUND',
                'message' => 'Неизвестный маршрут API. Используйте GET /v1/stocks с параметром xml_id (path в query или PATH_INFO).',
                'api_version' => self::API_VERSION,
            ],
            JSON_UNESCAPED_UNICODE
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
            return '/';
        }

        return '/' . $raw;
    }

    private function handleGetStocks(): void
    {
        $queryKey = isset($_GET['access_key']) ? (string) $_GET['access_key'] : null;
        $auth = asStockApiAuthBySecretKey($queryKey);
        if (!$auth['ok']) {
            http_response_code(401);
            echo json_encode(
                [
                    'ok' => false,
                    'error' => 'UNAUTHORIZED',
                    'message' => $auth['message'] ?? 'Требуется авторизация.',
                    'api_version' => self::API_VERSION,
                ],
                JSON_UNESCAPED_UNICODE
            );

            return;
        }

        $xmlId = isset($_GET['xml_id']) ? (string) $_GET['xml_id'] : '';
        $result = StockReadService::queryByXmlId($xmlId);
        http_response_code($result['http_code']);
        echo json_encode($result['data'], JSON_UNESCAPED_UNICODE);
    }
}
