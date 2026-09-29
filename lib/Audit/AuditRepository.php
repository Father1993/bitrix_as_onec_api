<?php

namespace As\OnecApi\Audit;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Exception;
use Bitrix\Main\Type\DateTime;

final class AuditRepository implements AuditRepositoryInterface
{
    private const TABLE = 'b_as_onec_api_audit_log';

    public static function installSchema(): void
    {
        $connection = Application::getConnection();
        if (!$connection->isTableExists(self::TABLE)) {
            AuditLogTable::getEntity()->createDbTable();
        }

        foreach ([
            'CREATE UNIQUE INDEX ix_as_onec_audit_request ON ' . self::TABLE . ' (REQUEST_ID)',
            'CREATE INDEX ix_as_onec_audit_created ON ' . self::TABLE . ' (CREATED_AT)',
            'CREATE INDEX ix_as_onec_audit_operation_status ON ' . self::TABLE . ' (OPERATION, STATUS)',
        ] as $sql) {
            try {
                $connection->queryExecute($sql);
            } catch (Exception $ignored) {
                // Индекс уже существует или СУБД создала его при предыдущем конкурентном запросе.
            }
        }
    }

    public function importLegacyJsonl(string $file): int
    {
        if (!is_file($file) || !is_readable($file)) {
            return 0;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return 0;
        }
        $imported = 0;
        foreach ($lines as $line) {
            $legacy = json_decode($line, true);
            if (!is_array($legacy) || empty($legacy['request_id'])) {
                continue;
            }
            $timestamp = strtotime((string) ($legacy['at'] ?? ''));
            $createdAt = $timestamp === false
                ? new DateTime()
                : new DateTime(date('Y-m-d H:i:s', $timestamp));
            $request = isset($legacy['request']) && is_array($legacy['request']) ? $legacy['request'] : [];
            $failed = self::nullableInt($legacy['failed'] ?? null);
            $ok = !empty($legacy['ok']);
            $entry = [
                'request_id' => (string) $legacy['request_id'],
                'created_at' => $createdAt,
                'operation' => (string) ($legacy['operation'] ?? 'unknown'),
                'status' => !$ok ? 'error' : (($failed ?? 0) > 0 ? 'partial' : 'success'),
                'http_code' => (int) ($legacy['http_code'] ?? 0),
                'total' => self::nullableInt($legacy['total'] ?? null),
                'updated' => self::nullableInt($legacy['updated'] ?? null),
                'failed' => $failed,
                'no_change' => self::nullableInt($legacy['no_change'] ?? null),
                'client_ip' => (string) ($legacy['client_ip'] ?? ''),
                'body_sha256' => (string) ($request['body_sha256'] ?? ''),
                'body_bytes' => self::nullableInt($request['body_bytes'] ?? null),
                'details' => json_encode([
                    'request' => $request,
                    'response' => $legacy['response'] ?? [],
                    'errors' => $legacy['errors'] ?? [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ];
            if ($this->add($entry)) {
                ++$imported;
            }
        }

        return $imported;
    }

    /** @param array<string,mixed> $entry */
    public function add(array $entry): bool
    {
        try {
            $result = AuditLogTable::add([
                'REQUEST_ID' => (string) $entry['request_id'],
                'CREATED_AT' => $entry['created_at'],
                'OPERATION' => (string) $entry['operation'],
                'STATUS' => (string) $entry['status'],
                'HTTP_CODE' => (int) $entry['http_code'],
                'TOTAL' => $entry['total'],
                'UPDATED' => $entry['updated'],
                'FAILED' => $entry['failed'],
                'NO_CHANGE' => $entry['no_change'],
                'CLIENT_IP' => (string) $entry['client_ip'],
                'BODY_SHA256' => (string) $entry['body_sha256'],
                'BODY_BYTES' => $entry['body_bytes'],
                'DETAILS' => (string) $entry['details'],
            ]);

            return $result->isSuccess();
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    public function find(array $filter = [], int $limit = 50, int $offset = 0, array $order = ['CREATED_AT' => 'DESC']): array
    {
        try {
            $result = AuditLogTable::getList([
                'select' => ['*'],
                'filter' => $filter,
                'order' => $order,
                'limit' => max(1, min(200, $limit)),
                'offset' => max(0, $offset),
            ]);
        } catch (\Throwable $ignored) {
            return [];
        }
        $records = [];
        while ($row = $result->fetch()) {
            $records[] = $this->toRecord($row);
        }

        return $records;
    }

    public function count(array $filter = []): int
    {
        try {
            return AuditLogTable::getCount($filter);
        } catch (\Throwable $ignored) {
            return 0;
        }
    }

    public function clear(array $filter = []): int
    {
        $ids = [];
        $result = AuditLogTable::getList(['select' => ['ID'], 'filter' => $filter]);
        while ($row = $result->fetch()) {
            $ids[] = (int) $row['ID'];
        }
        foreach ($ids as $id) {
            AuditLogTable::delete($id);
        }

        return count($ids);
    }

    public function cleanup(int $retentionDays, int $maxRecords): int
    {
        $deleted = 0;
        $retentionDays = max(1, $retentionDays);
        $cutoff = (new DateTime())->add('-' . $retentionDays . ' days');
        $result = AuditLogTable::getList([
            'select' => ['ID'],
            'filter' => ['<CREATED_AT' => $cutoff],
            'order' => ['ID' => 'ASC'],
            'limit' => 1000,
        ]);
        while ($row = $result->fetch()) {
            AuditLogTable::delete((int) $row['ID']);
            ++$deleted;
        }

        $overflow = max(0, $this->count() - max(1000, $maxRecords));
        if ($overflow === 0) {
            return $deleted;
        }
        $result = AuditLogTable::getList([
            'select' => ['ID'],
            'order' => ['ID' => 'ASC'],
            'limit' => min(1000, $overflow),
        ]);
        while ($row = $result->fetch()) {
            AuditLogTable::delete((int) $row['ID']);
            ++$deleted;
        }

        return $deleted;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function toRecord(array $row): array
    {
        $details = json_decode((string) ($row['DETAILS'] ?? ''), true);
        $details = is_array($details) ? $details : [];

        return array_merge($details, [
            'id' => (int) $row['ID'],
            'request_id' => (string) $row['REQUEST_ID'],
            'at' => $row['CREATED_AT'] instanceof DateTime ? $row['CREATED_AT']->format('c') : (string) $row['CREATED_AT'],
            'operation' => (string) $row['OPERATION'],
            'status' => (string) $row['STATUS'],
            'http_code' => (int) $row['HTTP_CODE'],
            'total' => self::nullableInt($row['TOTAL'] ?? null),
            'updated' => self::nullableInt($row['UPDATED'] ?? null),
            'failed' => self::nullableInt($row['FAILED'] ?? null),
            'no_change' => self::nullableInt($row['NO_CHANGE'] ?? null),
            'client_ip' => (string) ($row['CLIENT_IP'] ?? ''),
        ]);
    }

    private static function nullableInt($value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
