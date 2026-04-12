<?php

use As\OnecApi\Installer;
use Bitrix\Main\ModuleManager;

IncludeModuleLangFile(__FILE__);

/**
 * Имя класса as_onec_api задано ядром: CModule::CreateModuleObject() ищет str_replace('.', '_', MODULE_ID).
 */
class as_onec_api extends CModule
{
    public $MODULE_ID = 'as.onec_api';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;

    /** @var string Партнёрские модули (ID с точкой): для partner_modules.php. */
    public $PARTNER_NAME;

    /** @var string Ссылка на автора / репозиторий. */
    public $PARTNER_URI;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = GetMessage('AS_ONEC_API_MODULE_NAME');
        $this->MODULE_DESCRIPTION = GetMessage('AS_ONEC_API_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = 'Andrej Spinej';
        $this->PARTNER_URI = 'https://github.com/Father1993/bitrix-as-onecstock';
    }

    public function DoInstall()
    {
        global $USER, $APPLICATION;

        if (!is_object($USER) || !$USER->IsAdmin()) {
            $APPLICATION->ThrowException(GetMessage('AS_ONEC_API_INSTALL_PERM'));

            return false;
        }

        try {
            ModuleManager::registerModule($this->MODULE_ID);
            Installer::migrateOptionsFromLegacyStockModule();
            $this->InstallDB();
            $this->InstallEvents();
        } catch (\Throwable $e) {
            $this->rollbackInstall();
            $APPLICATION->ThrowException($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Откат при ошибке после registerModule: снять обработчики и удалить запись модуля.
     */
    private function rollbackInstall(): void
    {
        if (!ModuleManager::isModuleInstalled($this->MODULE_ID)) {
            return;
        }
        try {
            $this->UnInstallEvents();
        } catch (\Throwable $ignore) {
        }
        try {
            ModuleManager::unRegisterModule($this->MODULE_ID);
        } catch (\Throwable $ignore) {
        }
    }

    public function DoUninstall()
    {
        global $USER, $APPLICATION;

        if (!is_object($USER) || !$USER->IsAdmin()) {
            $APPLICATION->ThrowException(GetMessage('AS_ONEC_API_INSTALL_PERM'));

            return false;
        }

        try {
            $this->UnInstallEvents();
            $this->UnInstallDB();
        } catch (\Throwable $e) {
            $APPLICATION->ThrowException($e->getMessage());

            return false;
        }

        return true;
    }

    public function InstallDB($arParams = [])
    {
        require_once dirname(__DIR__) . '/lib/installer.php';
        Installer::grantAdminGroupsWriteAccess();

        return true;
    }

    public function UnInstallDB($arParams = [])
    {
        global $APPLICATION;

        $APPLICATION->DelGroupRight($this->MODULE_ID);
        ModuleManager::unRegisterModule($this->MODULE_ID);

        return true;
    }

    public function InstallEvents()
    {
        return true;
    }

    public function InstallFiles()
    {
        return true;
    }

    public function UnInstallFiles()
    {
        return true;
    }

    public function UnInstallEvents()
    {
        require_once dirname(__DIR__) . '/lib/installer.php';
        Installer::unregisterLegacyRestHandlers();

        return true;
    }
}
