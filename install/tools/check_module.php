<?php
define('STOP_STATISTICS', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

global $USER;
if (!is_object($USER) || !$USER->IsAdmin()) {
    die('Access denied');
}

if (!defined('AS_ONECSTOCK_DIAG_URL') || AS_ONECSTOCK_DIAG_URL !== true) {
    die('Access denied');
}

\Bitrix\Main\Loader::includeModule('main');

$mid = 'as.onec_api';

echo "<h1>Проверка модуля {$mid}</h1>";

// 1. Проверка регистрации в b_module
$isInstalled = \Bitrix\Main\ModuleManager::isModuleInstalled($mid);
echo "<p><strong>Модуль установлен (ModuleManager):</strong> " . ($isInstalled ? 'ДА' : 'НЕТ') . "</p>";

// 2. Проверка объекта модуля
$Module = CModule::CreateModuleObject($mid);
echo "<p><strong>CModule::CreateModuleObject:</strong> " . ($Module ? 'OK, версия: ' . $Module->MODULE_VERSION : 'FAILED') . "</p>";

// 3. Проверка прав
$RIGHT = $APPLICATION->GetGroupRight($mid);
echo "<p><strong>GetGroupRight:</strong> {$RIGHT}</p>";
echo "<p><strong>USER->IsAdmin:</strong> " . ($USER->IsAdmin() ? 'ДА' : 'НЕТ') . "</p>";

// 4. Проверка записи в b_module
$res = $DB->Query(
    "SELECT * FROM b_module WHERE MODULE_ID='" . $DB->ForSql($mid) . "'",
    false,
    'FILE: ' . __FILE__ . '<br>LINE: ' . __LINE__
);
if ($row = $res->Fetch()) {
    echo "<p><strong>Запись в b_module:</strong> найдена, INSTALLED='{$row['INSTALLED']}'</p>";
} else {
    echo "<p><strong style='color:red'>Запись в b_module НЕ найдена!</strong></p>";
}

// 5. Проверка Option
use Bitrix\Main\Config\Option;
echo "<p><strong>Option::get max_items:</strong> " . htmlspecialcharsbx(Option::get($mid, 'max_items', '(не задано)')) . "</p>";
echo "<p><strong>Option::get batch_size:</strong> " . htmlspecialcharsbx(Option::get($mid, 'batch_size', '(не задано)')) . "</p>";

echo "<hr><a href='/bitrix/admin/settings.php?mid={$mid}&lang=ru'>Перейти к настройкам</a>";

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
