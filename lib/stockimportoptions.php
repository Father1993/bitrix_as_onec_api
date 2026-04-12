<?php

namespace As\OnecApi;

use Bitrix\Main\Config\Option;

/**
 * Параметры импорта остатков: сначала из настроек модуля (b_option), иначе из констант ONEC_STOCK_IMPORT_*
 * в {@see local/php_interface/include/config.php} для обратной совместимости.
 */
final class StockImportOptions
{
    public const MODULE_ID = 'as.onec_api';

    public static function getMaxBodyBytes(): int
    {
        $v = trim((string) Option::get(self::MODULE_ID, 'max_body_bytes', ''));
        if ($v !== '' && ctype_digit($v)) {
            return max(1024, (int) $v);
        }
        if (defined('ONEC_STOCK_IMPORT_MAX_BODY_BYTES')) {
            return max(1024, (int) ONEC_STOCK_IMPORT_MAX_BODY_BYTES);
        }

        return 10 * 1024 * 1024;
    }

    public static function getMaxItems(): int
    {
        return self::positiveIntOption(
            'max_items',
            'ONEC_STOCK_IMPORT_MAX_ITEMS',
            10000
        );
    }

    public static function getBatchSize(): int
    {
        return self::positiveIntOption(
            'batch_size',
            'ONEC_STOCK_IMPORT_BATCH_SIZE',
            100
        );
    }

    public static function getDefaultStoreId(): int
    {
        $v = trim((string) Option::get(self::MODULE_ID, 'default_store_id', ''));
        if ($v !== '' && ctype_digit($v)) {
            return max(0, (int) $v);
        }
        if (defined('ONEC_STOCK_IMPORT_DEFAULT_STORE_ID')) {
            return max(0, (int) ONEC_STOCK_IMPORT_DEFAULT_STORE_ID);
        }

        return 0;
    }

    private static function positiveIntOption(string $optionKey, string $constantName, int $default): int
    {
        $v = trim((string) Option::get(self::MODULE_ID, $optionKey, ''));
        if ($v !== '' && ctype_digit($v)) {
            return max(1, (int) $v);
        }
        if (defined($constantName)) {
            return max(1, (int) constant($constantName));
        }

        return max(1, $default);
    }
}
