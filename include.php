<?php

/**
 * Подключение модуля as.onec_api (1C JSON API: остатки, цены, товары).
 *
 * PSR-4: As\OnecApi → lib/ (см. .settings.php). Явный автозагрузчик ниже дублирует маппинг ядра: на части
 * установок Bitrix не регистрирует psr-4 из .settings.php для local-модулей до очистки кеша — без него классы
 * (например JsonApiKernel) недоступны сразу после Install.
 *
 * Процедурный {@see include/stock_import_engine.php} не подключается здесь: ленивая загрузка через
 * {@see \As\OnecApi\Stock\StockEngineBootstrap::ensureLoaded()} при обработке HTTP-маршрутов (см. JsonApiKernel).
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'As\\OnecApi\\';
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            return;
        }
        $relative = substr($class, $len);
        if ($relative === '') {
            return;
        }
        $file = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    },
    true,
    true
);

\As\OnecApi\Installer::syncIfNewVersion();
