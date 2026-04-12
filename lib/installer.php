<?php

namespace As\Onecstock;

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

final class Installer
{
    public const MODULE_ID = 'as.onecstock';

    /** @var bool Одна проверка версии за HTTP-запрос при повторном includeModule. */
    private static $syncIfNewVersionRan = false;

    public static function syncRestEvents(): void
    {
        if (!ModuleManager::isModuleInstalled('rest')) {
            return;
        }
        $em = EventManager::getInstance();
        $em->unregisterEventHandler(
            'rest',
            'OnRestServiceBuildDescription',
            self::MODULE_ID,
            '\\As\\Onecstock\\Rest\\StockImportService',
            'onRestServiceBuildDescription'
        );
        $em->registerEventHandler(
            'rest',
            'OnRestServiceBuildDescription',
            self::MODULE_ID,
            '\\As\\Onecstock\\Rest\\RestService',
            'onRestServiceBuildDescription'
        );
    }

    /** Перерегистрация REST при смене версии файлов модуля (деплой без переустановки). */
    public static function syncIfNewVersion(): void
    {
        if (self::$syncIfNewVersionRan) {
            return;
        }
        self::$syncIfNewVersionRan = true;

        $versionFile = dirname(__DIR__) . '/install/version.php';
        if (!is_file($versionFile)) {
            return;
        }
        $arModuleVersion = [];
        include $versionFile;
        $ver = (string) ($arModuleVersion['VERSION'] ?? '');
        if ($ver === '') {
            return;
        }
        $saved = Option::get(self::MODULE_ID, 'install_script_version', '');
        if ($saved === $ver) {
            return;
        }
        self::syncRestEvents();
        if (ModuleManager::isModuleInstalled('rest')) {
            Option::set(self::MODULE_ID, 'install_script_version', $ver);
        }
    }
}
