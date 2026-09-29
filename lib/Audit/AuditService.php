<?php

namespace As\OnecApi\Audit;

use As\OnecApi\Http\RequestAuditLog;
use Bitrix\Main\Type\DateTime;

final class AuditService
{
    private static ?AuditRepositoryInterface $repository = null;

    /** @param array{http_code?:int,data?:array<string,mixed>} $result */
    public static function record(string $operation, array $result): ?string
    {
        $requestId = RequestAuditLog::newRequestId();
        $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
        $details = RequestAuditLog::safeDetails($operation, $data);
        $failed = RequestAuditLog::intOrNull($data['failed'] ?? null);
        $ok = !empty($data['ok']);
        $entry = [
            'request_id' => $requestId,
            'created_at' => new DateTime(),
            'operation' => $operation,
            'status' => !$ok ? 'error' : (($failed ?? 0) > 0 ? 'partial' : 'success'),
            'http_code' => (int) ($result['http_code'] ?? 0),
            'total' => RequestAuditLog::intOrNull($data['total'] ?? null),
            'updated' => RequestAuditLog::intOrNull($data['updated'] ?? null),
            'failed' => $failed,
            'no_change' => RequestAuditLog::intOrNull($data['no_change'] ?? null),
            'client_ip' => RequestAuditLog::clientIp(),
            'body_sha256' => (string) ($details['request']['body_sha256'] ?? ''),
            'body_bytes' => RequestAuditLog::intOrNull($details['request']['body_bytes'] ?? null),
            'details' => self::detailsJson($details),
        ];

        return self::repository()->add($entry) ? $requestId : null;
    }

    /** @return list<array<string,mixed>> */
    public static function read(int $limit = 50): array
    {
        return self::repository()->find([], $limit);
    }

    /** @param array<string,mixed> $filter @return list<array<string,mixed>> */
    public static function find(array $filter, int $limit, int $offset = 0): array
    {
        return self::repository()->find($filter, $limit, $offset);
    }

    /** @param array<string,mixed> $filter */
    public static function count(array $filter = []): int
    {
        return self::repository()->count($filter);
    }

    /** @param array<string,mixed> $filter */
    public static function clear(array $filter = []): int
    {
        return self::repository()->clear($filter);
    }

    public static function cleanup(int $retentionDays, int $maxRecords): int
    {
        return self::repository()->cleanup($retentionDays, $maxRecords);
    }

    public static function repository(): AuditRepositoryInterface
    {
        return self::$repository ??= new AuditRepository();
    }

    /** @param array<string,mixed> $details */
    private static function detailsJson(array $details): string
    {
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        if (strlen($json) <= 60000) {
            return $json;
        }
        if (isset($details['request']['items']) && is_array($details['request']['items'])) {
            $details['request']['items'] = array_slice($details['request']['items'], 0, 5);
        }
        if (isset($details['errors']) && is_array($details['errors'])) {
            $details['errors'] = array_slice($details['errors'], 0, 5);
        }
        $details['details_truncated'] = true;

        return json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
