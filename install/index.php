<?php

use As\Onecstock\Installer;
use Bitrix\Main\GroupTable;
use Bitrix\Main\ModuleManager;

IncludeModuleLangFile(__FILE__);

/**
 * Имя класса as_onecstock задано ядром: CModule::CreateModuleObject() ищет str_replace('.', '_', MODULE_ID).
 */
class as_onecstock extends CModule
{
    public $MODULE_ID = 'as.onecstock';
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
        $this->MODULE_NAME = GetMessage('AS_ONECSTOCK_MODULE_NAME');
        $this->MODULE_DESCRIPTION = GetMessage('AS_ONECSTOCK_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = 'Andrej Spinej';
        $this->PARTNER_URI = 'https://github.com/Father1993/bitrix-as-onecstock';
    }

    public function DoInstall()
    {
        global $USER, $APPLICATION;

        if (!is_object($USER) || !$USER->IsAdmin()) {
            $APPLICATION->ThrowException(GetMessage('AS_ONECSTOCK_INSTALL_PERM'));

            return false;
        }

        try {
            ModuleManager::registerModule($this->MODULE_ID);
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
            $APPLICATION->ThrowException(GetMessage('AS_ONECSTOCK_INSTALL_PERM'));

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
        global $APPLICATION;

        $result = GroupTable::getList([
            'filter' => ['=ADMIN' => 'Y', '=ACTIVE' => 'Y'],
            'select' => ['ID'],
        ]);
        while ($row = $result->fetch()) {
            $APPLICATION->SetGroupRight($this->MODULE_ID, (int) $row['ID'], 'W');
        }

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
        require_once dirname(__DIR__) . '/lib/installer.php';
        Installer::syncRestEvents();

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
        $em = \Bitrix\Main\EventManager::getInstance();
        $em->unregisterEventHandler(
            'rest',
            'OnRestServiceBuildDescription',
            $this->MODULE_ID,
            '\\As\\Onecstock\\Rest\\RestService',
            'onRestServiceBuildDescription'
        );
        $em->unregisterEventHandler(
            'rest',
            'OnRestServiceBuildDescription',
            $this->MODULE_ID,
            '\\As\\Onecstock\\Rest\\StockImportService',
            'onRestServiceBuildDescription'
        );

        return true;
    }
}
