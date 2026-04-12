<?php

defined('B_PROLOG_INCLUDED') || die();

use Bitrix\Main\Config\Option;
use Bitrix\Main\HttpApplication;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/** @global CMain $APPLICATION */

$moduleId = 'as.onecstock';

if (!Loader::includeModule($moduleId)) {
    return;
}

Loc::loadMessages(__FILE__);

$request = HttpApplication::getInstance()->getContext()->getRequest();

$RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($RIGHT < 'R') {
    return;
}

if ($request->isPost() && check_bitrix_sessid() && $RIGHT >= 'W') {
    if ($request->getPost('RestoreDefaults') !== null) {
        foreach (['max_items', 'batch_size', 'max_body_bytes', 'default_store_id'] as $name) {
            Option::delete($moduleId, ['name' => $name]);
        }
    } elseif ($request->getPost('Update') !== null) {
        $maxItems = trim((string) $request->getPost('max_items'));
        Option::set($moduleId, 'max_items', $maxItems === '' ? '' : (string) max(1, (int) $maxItems));

        $batchSize = trim((string) $request->getPost('batch_size'));
        Option::set($moduleId, 'batch_size', $batchSize === '' ? '' : (string) max(1, (int) $batchSize));

        $maxBody = trim((string) $request->getPost('max_body_bytes'));
        Option::set($moduleId, 'max_body_bytes', $maxBody === '' ? '' : (string) max(1024, (int) $maxBody));

        $defStore = trim((string) $request->getPost('default_store_id'));
        Option::set($moduleId, 'default_store_id', $defStore === '' ? '' : (string) max(0, (int) $defStore));
    }
}

$maxItems = Option::get($moduleId, 'max_items', '');
$batchSize = Option::get($moduleId, 'batch_size', '');
$maxBodyBytes = Option::get($moduleId, 'max_body_bytes', '');
$defaultStoreId = Option::get($moduleId, 'default_store_id', '');

$aTabs = [
    [
        'DIV' => 'as_onecstock',
        'TAB' => Loc::getMessage('AS_ONECSTOCK_OPTIONS_TAB'),
        'ICON' => '',
        'TITLE' => Loc::getMessage('AS_ONECSTOCK_OPTIONS_TAB_TITLE'),
    ],
];

$tabControl = new CAdminTabControl('tabControl', $aTabs);
$tabControl->Begin();
?>
<form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>?mid=<?= urlencode(
    $moduleId
) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <?php
    $tabControl->BeginNextTab();
    ?>
    <tr>
        <td colspan="2"><?= Loc::getMessage('AS_ONECSTOCK_OPTIONS_HINT_EMPTY') ?></td>
    </tr>
    <tr>
        <td width="40%"><?= Loc::getMessage('AS_ONECSTOCK_OPTIONS_MAX_ITEMS') ?>:</td>
        <td width="60%"><input type="text" size="12" name="max_items" value="<?= htmlspecialcharsbx(
            $maxItems
        ) ?>"></td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('AS_ONECSTOCK_OPTIONS_BATCH_SIZE') ?>:</td>
        <td><input type="text" size="12" name="batch_size" value="<?= htmlspecialcharsbx(
            $batchSize
        ) ?>"></td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('AS_ONECSTOCK_OPTIONS_MAX_BODY_BYTES') ?>:</td>
        <td><input type="text" size="12" name="max_body_bytes" value="<?= htmlspecialcharsbx(
            $maxBodyBytes
        ) ?>"></td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('AS_ONECSTOCK_OPTIONS_DEFAULT_STORE_ID') ?>:</td>
        <td><input type="text" size="12" name="default_store_id" value="<?= htmlspecialcharsbx(
            $defaultStoreId
        ) ?>"></td>
    </tr>
    <?php
    $tabControl->EndTab();
    $tabControl->Buttons();
    ?>
    <input <?php if ($RIGHT < 'W') {
        echo 'disabled';
           } ?> type="submit" name="Update" value="<?= htmlspecialcharsbx(
               Loc::getMessage('AS_ONECSTOCK_OPTIONS_SAVE')
           ) ?>">
    <input <?php if ($RIGHT < 'W') {
        echo 'disabled';
           } ?> type="submit" name="RestoreDefaults" value="<?= htmlspecialcharsbx(
               Loc::getMessage('AS_ONECSTOCK_OPTIONS_RESTORE_DEFAULTS')
           ) ?>">
    <?php
    $tabControl->End();
    ?>
</form>
