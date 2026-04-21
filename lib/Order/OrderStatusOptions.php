<?php

namespace As\OnecApi\Order;

use Bitrix\Main\Config\Option;

final class OrderStatusOptions
{
    public const MODULE_ID = 'as.onec_api';

    /**
     * @return array<string, mixed>
     */
    public static function getStatusMap(): array
    {
        return self::jsonOption('order_status_map_json', 'ONEC_ORDER_STATUS_MAP');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getAllowedTransitions(): array
    {
        return self::jsonOption('order_status_allowed_transitions_json', 'ONEC_ORDER_STATUS_ALLOWED_TRANSITIONS');
    }

    public static function allowPaymentSync(): bool
    {
        return self::boolOption('order_status_sync_payment', 'ONEC_ORDER_STATUS_SYNC_PAYMENT', false);
    }

    public static function allowShipmentSync(): bool
    {
        return self::boolOption('order_status_sync_shipment', 'ONEC_ORDER_STATUS_SYNC_SHIPMENT', false);
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonOption(string $optionKey, string $constantName): array
    {
        $raw = trim((string) Option::get(self::MODULE_ID, $optionKey, ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (defined($constantName)) {
            $value = constant($constantName);
            if (is_array($value)) {
                return $value;
            }
            if (is_string($value) && trim($value) !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    private static function boolOption(string $optionKey, string $constantName, bool $default): bool
    {
        $raw = strtoupper(trim((string) Option::get(self::MODULE_ID, $optionKey, '')));
        if ($raw !== '') {
            return in_array($raw, ['Y', '1', 'TRUE'], true);
        }

        if (defined($constantName)) {
            $value = constant($constantName);
            if (is_bool($value)) {
                return $value;
            }

            $rawValue = strtoupper(trim((string) $value));
            if ($rawValue !== '') {
                return in_array($rawValue, ['Y', '1', 'TRUE'], true);
            }
        }

        return $default;
    }
}
