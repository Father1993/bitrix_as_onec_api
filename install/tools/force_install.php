<?php

/**
 * Аварийная установка модуля as.onecstock из браузера, если кнопка на partner_modules.php «молчит».
 *
 * Откройте в браузере (будучи залогиненным администратором):
 * /local/modules/as.onecstock/install/tools/force_install.php
 *
 * После успешной установки УДАЛИТЕ этот файл с продакшена (либо весь каталог install/tools/).
 */

define('STOP_STATISTICS', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('BX_SECURITY_SESSION_READONLY', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

/** @global CMain $APPLICATION */
/** @global CUser $USER */

if (!is_object($USER) || !$USER->IsAdmin()) {
    $APPLICATION->AuthForm('');
}

\Bitrix\Main\Loader::includeModule('main');

$mid = 'as.onecstock';

if (\Bitrix\Main\ModuleManager::isModuleInstalled($mid)) {
    LocalRedirect('/bitrix/admin/partner_modules.php?lang=' . LANGUAGE_ID . '&mod=' . rawurlencode($mid) . '&result=OK');
}

$Module = CModule::CreateModuleObject($mid);
if (!$Module) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    echo '<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message">CreateModuleObject('
        . htmlspecialcharsbx($mid) . ') failed. Check install/index.php and PHP error log.</div></div>';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    exit;
}

if ($Module->DoInstall() !== false) {
    LocalRedirect('/bitrix/admin/partner_modules.php?lang=' . LANGUAGE_ID . '&mod=' . rawurlencode($mid) . '&result=OK');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$msg = 'DoInstall returned false.';
if ($e = $APPLICATION->GetException()) {
    $msg = $e->GetString();
}
echo '<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message">' . $msg . '</div></div>';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
