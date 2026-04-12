<?php

namespace As\Onecstock;

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

final class Installer
{
    public const MODULE_ID = 'as.onecstock';

    /** @var bool Одна проверка версии за HTTP-запрос при повторном includeModule. */
    private static $syncIfNewVersionRan = false;

    /**
     * Снятие устаревших обработчиков OnRestServiceBuildDescription (версии с REST).
     * Вызывать при обновлении модуля и при деинсталляции.
     */
    public static function unregisterLegacyRestHandlers(): void
    {
        if (!ModuleManager::isModuleInstalled('rest')) {
            return;
        }
        $em = EventManager::getInstance();
        $em->unregisterEventHandler(
            'rest',
            'OnRestServiceBuildDescription',
            self::MODULE_ID,
            '\\As\\Onecstock\\Rest\\RestService',
            'onRestServiceBuildDescription'
        );
        $em->unregisterEventHandler(
            'rest',
            'OnRestServiceBuildDescription',
            self::MODULE_ID,
            '\\As\\Onecstock\\Rest\\StockImportService',
            'onRestServiceBuildDescription'
        );
    }

    /** При смене версии файлов модуля: снять REST (если был), права админов, запись install_script_version. */
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
        self::unregisterLegacyRestHandlers();
        self::grantAdminGroupsWriteAccess();
        Option::set(self::MODULE_ID, 'install_script_version', $ver);
    }

    /**
     * Права W для админ-групп (ADMIN=Y в b_group). Через CGroup — поле ADMIN не в map D7 GroupTable.
     */
    public static function grantAdminGroupsWriteAccess(): void
    {
        global $APPLICATION;

        if (!Loader::includeModule('main')) {
            return;
        }

        $rs = \CGroup::GetList('c_sort', 'asc', ['ADMIN' => 'Y', 'ACTIVE' => 'Y']);
        while ($row = $rs->Fetch()) {
            $APPLICATION->SetGroupRight(self::MODULE_ID, (int) $row['ID'], 'W');
        }
    }
}
