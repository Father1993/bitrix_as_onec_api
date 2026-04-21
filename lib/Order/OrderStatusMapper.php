<?php

namespace As\OnecApi\Order;

final class OrderStatusMapper
{
    /**
     * @param array<string, mixed> $row
     * @return array{ok:true,source_code:string,target_status:string,paid:?bool,allow_delivery:?bool,deducted:?bool,comment:string,event_at:string}|array{ok:false,message:string}
     */
    public static function map(array $row): array
    {
        $sourceCode = trim((string) (
            $row['status_code_1c']
            ?? $row['status_code']
            ?? $row['status']
            ?? $row['event_code']
            ?? ''
        ));

        $map = OrderStatusOptions::getStatusMap();
        $mapped = null;
        if ($sourceCode !== '' && array_key_exists($sourceCode, $map)) {
            $mapped = $map[$sourceCode];
        }

        $targetStatus = '';
        $paid = self::extractNullableBool($row, 'paid');
        $allowDelivery = self::extractNullableBool($row, 'allow_delivery');
        $deducted = self::extractNullableBool($row, 'deducted');

        if (is_string($mapped)) {
            $targetStatus = trim($mapped);
        } elseif (is_array($mapped)) {
            $targetStatus = trim((string) ($mapped['status_id'] ?? $mapped['order_status'] ?? ''));
            if ($paid === null && array_key_exists('paid', $mapped)) {
                $paid = self::normalizeBoolValue($mapped['paid']);
            }
            if ($allowDelivery === null && array_key_exists('allow_delivery', $mapped)) {
                $allowDelivery = self::normalizeBoolValue($mapped['allow_delivery']);
            }
            if ($deducted === null && array_key_exists('deducted', $mapped)) {
                $deducted = self::normalizeBoolValue($mapped['deducted']);
            }
        } else {
            $targetStatus = $sourceCode;
        }

        $targetStatus = strtoupper($targetStatus);
        if ($targetStatus === '' && $paid === null && $allowDelivery === null && $deducted === null) {
            return ['ok' => false, 'message' => 'Нужен status_code_1c/status_code/status/event_code или явные флаги paid/allow_delivery/deducted.'];
        }

        $comment = trim((string) ($row['comment'] ?? $row['user_description'] ?? ''));
        $eventAt = trim((string) ($row['event_at'] ?? $row['event_date'] ?? $row['date'] ?? ''));

        return [
            'ok' => true,
            'source_code' => $sourceCode,
            'target_status' => $targetStatus,
            'paid' => $paid,
            'allow_delivery' => $allowDelivery,
            'deducted' => $deducted,
            'comment' => $comment,
            'event_at' => $eventAt,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function extractNullableBool(array $row, string $key): ?bool
    {
        if (!array_key_exists($key, $row)) {
            return null;
        }

        return self::normalizeBoolValue($row[$key]);
    }

    /**
     * @param mixed $value
     */
    private static function normalizeBoolValue($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['Y', 'YES', 'TRUE', '1'], true)) {
            return true;
        }

        if (in_array($normalized, ['N', 'NO', 'FALSE', '0'], true)) {
            return false;
        }

        return null;
    }
}
