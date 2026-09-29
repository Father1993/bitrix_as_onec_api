<?php

namespace As\OnecApi\Http;

/**
 * Короткий журнал POST-операций API.
 *
 * Не сохраняет ключи авторизации и полные тела запросов. Для диагностики сохраняются
 * безопасные метаданные, хэш тела, выбранные строки и результат обработки. Ротация
 * ограничена числом записей и размером файла.
 */
final class RequestAuditLog
{
    private const DEFAULT_LIMIT = 200;
    private const MAX_LIMIT = 200;
    private const MAX_FILE_BYTES = 4194304;
    private const MAX_ENTRY_BYTES = 131072;
    private const MAX_ERROR_DETAILS = 50;
    private const MAX_ITEM_DETAILS = 50;
    private const MAX_STRING_LENGTH = 512;
    private const FILE_NAME = 'as_1c_api_requests.jsonl';

    /**
     * @param array{http_code?:int,data?:array<string,mixed>} $result
     */
    public static function record(string $operation, array $result): ?string
    {
        $requestId = self::requestId();
        $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];

        $entry = [
            'request_id' => $requestId,
            'at' => date('c'),
            'operation' => $operation,
            'client_ip' => self::clientIp(),
            'http_code' => (int) ($result['http_code'] ?? 0),
            'ok' => !empty($data['ok']),
            'total' => self::intOrNull($data['total'] ?? null),
            'updated' => self::intOrNull($data['updated'] ?? null),
            'failed' => self::intOrNull($data['failed'] ?? null),
            'no_change' => self::intOrNull($data['no_change'] ?? null),
            'request' => self::requestDetails($operation, $data),
            'response' => self::responseDetails($data),
            'errors' => self::errorDetails($data['errors'] ?? []),
        ];

        $entry = self::fitEntry($entry);

        return self::append($entry) ? $requestId : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function read(int $limit = self::DEFAULT_LIMIT): array
    {
        $file = self::filePath();
        if ($file === null || !is_file($file)) {
            return [];
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            if (!@flock($handle, LOCK_SH)) {
                return [];
            }
            $contents = stream_get_contents($handle);
            @flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        $records = self::decodeLines((string) $contents);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        return array_reverse(array_slice(array_reverse($records), 0, $limit));
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function append(array $entry): bool
    {
        $file = self::filePath();
        if ($file === null) {
            return false;
        }

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                return false;
            }
            rewind($handle);
            $records = self::decodeLines((string) stream_get_contents($handle));
            $records[] = $entry;
            $records = array_slice($records, -self::limit());
            $lines = self::linesWithinLimit($records);

            rewind($handle);
            if (!@ftruncate($handle, 0)) {
                return false;
            }
            $written = fwrite($handle, implode("\n", $lines) . "\n");
            fflush($handle);
            @flock($handle, LOCK_UN);

            return $written !== false;
        } finally {
            fclose($handle);
        }
    }

    private static function filePath(): ?string
    {
        $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($docRoot === '') {
            return null;
        }
        $dir = rtrim($docRoot, '/\\') . '/upload/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        return $dir . '/' . self::FILE_NAME;
    }

    private static function limit(): int
    {
        if (defined('ONEC_API_REQUEST_LOG_LIMIT')) {
            return max(1, min(self::MAX_LIMIT, (int) ONEC_API_REQUEST_LOG_LIMIT));
        }

        return self::DEFAULT_LIMIT;
    }

    private static function requestId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return uniqid('onec_', true);
        }
    }

    private static function clientIp(): string
    {
        return substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
    }

    /**
     * @param mixed $value
     */
    private static function intOrNull($value): ?int
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
    private static function requestDetails(string $operation, array $data): array
    {
        $details = [
            'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST')),
            'path' => self::requestPath(),
            'content_type' => self::shortString((string) ($_SERVER['CONTENT_TYPE'] ?? '')),
        ];
        $raw = RequestBody::get();
        if ($raw === null) {
            return $details;
        }

        $details['body_bytes'] = strlen($raw);
        $details['body_sha256'] = hash('sha256', $raw);
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            $details['json_valid'] = false;

            return $details;
        }

        $items = self::extractItems($decoded);
        $details['json_valid'] = true;
        if ($items === null) {
            $details['items_count'] = 0;

            return $details;
        }

        $details['items_count'] = count($items);
        $details['items'] = self::itemDetails($operation, $items, $data['errors'] ?? []);

        return $details;
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

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function fitEntry(array $entry): array
    {
        $line = self::encode($entry);
        if ($line !== null && strlen($line) <= self::MAX_ENTRY_BYTES) {
            return $entry;
        }

        $entry['details_truncated'] = true;
        if (isset($entry['request']['items']) && is_array($entry['request']['items'])) {
            $entry['request']['items'] = array_slice($entry['request']['items'], 0, 5);
        }
        if (isset($entry['errors']) && is_array($entry['errors'])) {
            $entry['errors'] = array_slice($entry['errors'], 0, 5);
        }
        if (isset($entry['response']['results']) && is_array($entry['response']['results'])) {
            $entry['response']['results'] = array_slice($entry['response']['results'], 0, 5);
        }

        return $entry;
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return list<string>
     */
    private static function linesWithinLimit(array $records): array
    {
        $lines = [];
        $size = 0;
        foreach (array_reverse($records) as $record) {
            $line = self::encode($record);
            if ($line === null || strlen($line) > self::MAX_ENTRY_BYTES) {
                continue;
            }
            $lineSize = strlen($line) + 1;
            if ($lines !== [] && $size + $lineSize > self::MAX_FILE_BYTES) {
                break;
            }
            $lines[] = $line;
            $size += $lineSize;
        }

        return array_reverse($lines);
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function encode(array $entry): ?string
    {
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $line === false ? null : $line;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function decodeLines(string $contents): array
    {
        if ($contents === '') {
            return [];
        }

        $records = [];
        foreach (preg_split('/\R/', trim($contents)) ?: [] as $line) {
            $record = json_decode($line, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }
}
