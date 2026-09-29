<?php

namespace As\OnecApi;

use As\OnecApi\Audit\AuditAgent;
use As\OnecApi\Audit\AuditRepository;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

final class Installer
{
    public const MODULE_ID = 'as.onec_api';

    /** @var bool Одна проверка версии за HTTP-запрос при повторном includeModule. */
    private static $syncIfNewVersionRan = false;

    /**
     * Снятие устаревших обработчиков OnRestServiceBuildDescription (версии с REST).
     * Вызывать при обновлении модуля и при деинсталляции.
     *
     * Ядро ищет записи в БД по паре (module_id, class, method) и удаляет совпадения. PHP-классы
     * Rest\\RestService могут отсутствовать на диске — это нормально: unregister не загружает классы,
     * лишние вызовы без записи в b_module_to_module — no-op. Цель — очистить хвосты регистрации
     * для as.onec_api и устаревшего as.onecstock.
     */
    public static function unregisterLegacyRestHandlers(): void
    {
        if (!ModuleManager::isModuleInstalled('rest')) {
            return;
        }
        $em = EventManager::getInstance();
        $moduleIds = array_values(array_unique([self::MODULE_ID, 'as.onecstock']));
        foreach ($moduleIds as $mid) {
            $em->unregisterEventHandler(
                'rest',
                'OnRestServiceBuildDescription',
                $mid,
                '\\As\\OnecApi\\Rest\\RestService',
                'onRestServiceBuildDescription'
            );
            $em->unregisterEventHandler(
                'rest',
                'OnRestServiceBuildDescription',
                $mid,
                '\\As\\OnecApi\\Rest\\StockImportService',
                'onRestServiceBuildDescription'
            );
            $em->unregisterEventHandler(
                'rest',
                'OnRestServiceBuildDescription',
                $mid,
                '\\As\\Onecstock\\Rest\\RestService',
                'onRestServiceBuildDescription'
            );
            $em->unregisterEventHandler(
                'rest',
                'OnRestServiceBuildDescription',
                $mid,
                '\\As\\Onecstock\\Rest\\StockImportService',
                'onRestServiceBuildDescription'
            );
        }
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
        try {
            self::installAuditStorage();
            AuditAgent::register();
            self::installAdminFiles();
        } catch (\Throwable $ignored) {
            // Аудит не должен блокировать действующий обмен, если миграция временно недоступна.
            Option::set(self::MODULE_ID, 'audit_storage_error', 'Y');
        }
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

    /**
     * Копирование настроек из модуля as.onecstock при первой установке as.onec_api (b_option).
     */
    public static function migrateOptionsFromLegacyStockModule(): void
    {
        $legacy = 'as.onecstock';
        if (!ModuleManager::isModuleInstalled($legacy)) {
            return;
        }

        $keys = ['max_items', 'max_body_bytes', 'batch_size', 'default_store_id', 'install_script_version'];
        foreach ($keys as $key) {
            $v = Option::get($legacy, $key, '');
            if ($v !== '') {
                Option::set(self::MODULE_ID, $key, $v);
            }
        }
    }

    public static function installAuditStorage(): void
    {
        AuditRepository::installSchema();
        if (Option::get(self::MODULE_ID, 'audit_legacy_file_imported', 'N') !== 'Y') {
            $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
            if ($docRoot !== '') {
                (new AuditRepository())->importLegacyJsonl($docRoot . '/upload/logs/as_1c_api_requests.jsonl');
            }
            Option::set(self::MODULE_ID, 'audit_legacy_file_imported', 'Y');
        }
        Option::delete(self::MODULE_ID, ['name' => 'audit_storage_error']);
    }

    public static function installAdminFiles(): void
    {
        $source = dirname(__DIR__) . '/install/admin/as_onec_api_audit.php';
        $target = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/bitrix/admin/as_onec_api_audit.php';
        if (is_file($source) && $target !== '/bitrix/admin/as_onec_api_audit.php') {
            @copy($source, $target);
        }
    }
}
