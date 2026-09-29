<?php

namespace As\OnecApi\Http;

use As\OnecApi\Audit\AuditService;

/**
 * Санитизация диагностических данных POST-операций API.
 *
 * Не сохраняет ключи авторизации и полные тела запросов. Для диагностики сохраняются
 * безопасные метаданные, хэш тела, выбранные строки и результат обработки.
 */
final class RequestAuditLog
{
    private const MAX_ERROR_DETAILS = 50;
    private const MAX_ITEM_DETAILS = 50;
    private const MAX_STRING_LENGTH = 512;

    /**
     * @param array{http_code?:int,data?:array<string,mixed>} $result
     */
    public static function record(string $operation, array $result): ?string
    {
        return AuditService::record($operation, $result);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function read(int $limit = 50): array
    {
        return AuditService::read($limit);
    }

    public static function newRequestId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return uniqid('onec_', true);
        }
    }

    public static function clientIp(): string
    {
        return substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
    }

    /**
     * @param mixed $value
     */
    public static function intOrNull($value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param mixed $errors
     * @return list<array<string,mixed>>
     */
    private static function errorDetails($errors): array
    {
        if (!is_array($errors)) {
            return [];
        }

        $details = [];
        foreach (array_slice($errors, 0, self::MAX_ERROR_DETAILS) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $details[] = self::sanitizeArray($error);
        }

        return $details;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function safeDetails(string $operation, array $data): array
    {
        $details = [
            'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST')),
            'path' => self::requestPath(),
            'content_type' => self::shortString((string) ($_SERVER['CONTENT_TYPE'] ?? '')),
        ];
        $raw = RequestBody::get();
        if ($raw === null) {
            return self::detailsEnvelope($details, $data);
        }

        $details['body_bytes'] = strlen($raw);
        $details['body_sha256'] = hash('sha256', $raw);
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            $details['json_valid'] = false;

            return self::detailsEnvelope($details, $data);
        }

        $items = self::extractItems($decoded);
        $details['json_valid'] = true;
        if ($items === null) {
            $details['items_count'] = 0;

            return self::detailsEnvelope($details, $data);
        }

        $details['items_count'] = count($items);
        $details['items'] = self::itemDetails($operation, $items, $data['errors'] ?? []);

        return self::detailsEnvelope($details, $data);
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $data @return array<string,mixed> */
    private static function detailsEnvelope(array $request, array $data): array
    {
        return [
            'request' => $request,
            'response' => self::responseDetails($data),
            'errors' => self::errorDetails($data['errors'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function responseDetails(array $data): array
    {
        $keys = ['error', 'message', 'max_items', 'batch_index', 'errors_truncated'];
        $details = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $details[$key] = self::sanitizeValue($data[$key]);
            }
        }
        if (isset($data['results']) && is_array($data['results'])) {
            $details['results'] = array_map(
                static fn ($result): array => is_array($result) ? self::sanitizeArray($result) : [],
                array_slice($data['results'], 0, self::MAX_ERROR_DETAILS)
            );
        }

        return $details;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>|null
     */
    private static function extractItems(array $payload): ?array
    {
        if (isset($payload['items']) && is_array($payload['items'])) {
            return $payload['items'];
        }

        return self::isList($payload) ? $payload : null;
    }

    /**
     * @param list<mixed> $items
     * @param mixed $errors
     * @return list<array{index:int,item:array<string,mixed>|string}>
     */
    private static function itemDetails(string $operation, array $items, $errors): array
    {
        $indexes = range(0, min(9, max(0, count($items) - 1)));
        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (is_array($error) && isset($error['index']) && is_numeric($error['index'])) {
                    $indexes[] = (int) $error['index'];
                }
            }
        }
        $indexes = array_values(array_unique(array_filter(
            $indexes,
            static fn (int $index): bool => $index >= 0 && $index < count($items)
        )));
        $indexes = array_slice($indexes, 0, self::MAX_ITEM_DETAILS);

        $details = [];
        foreach ($indexes as $index) {
            $row = $items[$index];
            $details[] = [
                'index' => $index,
                'item' => is_array($row) ? self::sanitizeItem($operation, $row) : self::shortString((string) $row),
            ];
        }

        return $details;
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function sanitizeItem(string $operation, array $item): array
    {
        $allowed = [
            'product_xml_id', 'xml_id', 'XML_ID', 'product_id',
            'store_xml_id', 'store_id', 'store_code', 'code',
            'amount', 'quantity', 'catalog_group_id', 'price_type_id', 'CATALOG_GROUP_ID', 'price', 'currency',
            'title', 'name', 'address', 'site_id', 'active',
            'order_xml_id', 'order_id', 'id', 'status_code_1c', 'status_code', 'status', 'event_code',
            'paid', 'allow_delivery', 'deducted', 'event_at', 'event_date', 'date',
        ];
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $item)) {
                $safe[$key] = self::sanitizeValue($item[$key]);
            }
        }
        if ($safe === []) {
            $safe['schema'] = 'Поля позиции не входят в безопасный диагностический набор для ' . $operation . '.';
        }

        return $safe;
    }

    /**
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private static function sanitizeArray(array $value): array
    {
        $safe = [];
        foreach ($value as $key => $item) {
            $key = (string) $key;
            if (preg_match('/(?:access[_-]?key|api[_-]?key|password|token|authorization|secret)/i', $key)) {
                $safe[$key] = '[redacted]';
                continue;
            }
            $safe[$key] = self::sanitizeValue($item);
        }

        return $safe;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sanitizeValue($value)
    {
        if (is_string($value)) {
            return self::shortString($value);
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if (is_array($value)) {
            return self::sanitizeArray($value);
        }

        return gettype($value);
    }

    private static function shortString(string $value): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, self::MAX_STRING_LENGTH);
        }

        return substr($value, 0, self::MAX_STRING_LENGTH);
    }

    private static function requestPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) ? self::shortString($path) : '';
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected++) {
                return false;
            }
        }

        return true;
    }

}
