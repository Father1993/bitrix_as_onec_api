<?php

namespace As\OnecApi\Stock;

/**
 * Ленивая загрузка процедурного слоя {@see include/stock_import_engine.php} (глобальные asStock*).
 * Не подключается из {@see include.php}, чтобы не тянуть файл при каждом includeModule без сценария импорта/API.
 */
final class StockEngineBootstrap
{
    /** @var bool */
    private static $loaded = false;

    public static function ensureLoaded(): void
    {
        if (self::$loaded) {
            return;
        }

        $path = dirname(__DIR__, 2) . '/include/stock_import_engine.php';
        if (!is_file($path)) {
            throw new \RuntimeException('as.onec_api: include/stock_import_engine.php not found.');
        }

        require_once $path;
        self::$loaded = true;
    }
}
