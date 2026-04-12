<?php

namespace As\OnecApi\Http;

/**
 * Единый JSON-ответ API (версия, заголовок).
 */
final class JsonResponse
{
    public const API_VERSION = '1';

    public static function send(int $httpCode, array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if (!array_key_exists('api_version', $payload)) {
            $payload['api_version'] = self::API_VERSION;
        }
        http_response_code($httpCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Добавить api_version к данным ответа домена (массиву из сервиса).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function withApiVersion(array $data): array
    {
        if (!array_key_exists('api_version', $data)) {
            $data['api_version'] = self::API_VERSION;
        }

        return $data;
    }
}
