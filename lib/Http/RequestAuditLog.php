<?php

namespace As\OnecApi\Http;

/**
 * Короткий журнал POST-операций API.
 *
 * Не сохраняет ключи авторизации и тела запросов. Файл содержит только последние N итогов
 * выполнения, поэтому подходит для оперативной проверки обмена без бесконечного роста логов.
 */
final class RequestAuditLog
{
    private const DEFAULT_LIMIT = 200;
    private const MAX_LIMIT = 200;
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
            'errors' => self::errorSample($data['errors'] ?? []),
        ];

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

            $lines = [];
            foreach ($records as $record) {
                $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($line !== false) {
                    $lines[] = $line;
                }
            }

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
    private static function errorSample($errors): array
    {
        if (!is_array($errors)) {
            return [];
        }

        $sample = [];
        foreach (array_slice($errors, 0, 5) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $sample[] = array_filter([
                'index' => $error['index'] ?? null,
                'product_xml_id' => $error['product_xml_id'] ?? null,
                'product_id' => $error['product_id'] ?? null,
                'store_id' => $error['store_id'] ?? null,
                'order_xml_id' => $error['order_xml_id'] ?? null,
                'message' => isset($error['message']) ? (string) $error['message'] : null,
            ], static fn ($value): bool => $value !== null && $value !== '');
        }

        return $sample;
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
