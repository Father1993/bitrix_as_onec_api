<?php

namespace As\OnecApi\Audit;

interface AuditRepositoryInterface
{
    /** @param array<string,mixed> $entry */
    public function add(array $entry): bool;

    /** @param array<string,mixed> $filter @param array<string,string> $order @return list<array<string,mixed>> */
    public function find(array $filter = [], int $limit = 50, int $offset = 0, array $order = ['CREATED_AT' => 'DESC']): array;

    /** @param array<string,mixed> $filter */
    public function count(array $filter = []): int;

    /** @param array<string,mixed> $filter */
    public function clear(array $filter = []): int;

    public function cleanup(int $retentionDays, int $maxRecords): int;
}
