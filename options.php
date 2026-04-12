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

$canWrite = ($RIGHT >= 'W');

$formAction = $APPLICATION->GetCurPageParam(
    'mid=' . urlencode($moduleId) . '&lang=' . urlencode(LANGUAGE_ID),
    ['mid', 'lang']
);

$stickyValuesAfterError = false;

if ($request->isPost() && $canWrite) {
    $isRestore = $request->getPost('RestoreDefaults') !== null;
    $isUpdate = $request->getPost('Update') !== null;

    if ($isRestore || $isUpdate) {
        if (!check_bitrix_sessid()) {
            CAdminMessage::ShowMessage(['MESSAGE' => Loc::getMessage('AS_ONECSTOCK_OPTIONS_ERR_SESSID'), 'TYPE' => 'ERROR']);
        } elseif ($isRestore) {
            foreach (['max_items', 'batch_size', 'max_body_bytes', 'default_store_id'] as $name) {
                Option::delete($moduleId, ['name' => $name]);
            }
            CAdminMessage::ShowNote(Loc::getMessage('AS_ONECSTOCK_OPTIONS_SAVED'));
        } elseif ($isUpdate) {
            $maxItems = trim((string) $request->getPost('max_items'));
            $batchSize = trim((string) $request->getPost('batch_size'));
            $maxBody = trim((string) $request->getPost('max_body_bytes'));
            $defStore = trim((string) $request->getPost('default_store_id'));

            $err = null;
            if ($maxItems !== '' && (!ctype_digit($maxItems) || (int) $maxItems < 1)) {
                $err = Loc::getMessage('AS_ONECSTOCK_OPTIONS_ERR_MAX_ITEMS');
            } elseif ($batchSize !== '' && (!ctype_digit($batchSize) || (int) $batchSize < 1)) {
                $err = Loc::getMessage('AS_ONECSTOCK_OPTIONS_ERR_BATCH_SIZE');
            } elseif ($maxBody !== '' && (!ctype_digit($maxBody) || (int) $maxBody < 1024)) {
                $err = Loc::getMessage('AS_ONECSTOCK_OPTIONS_ERR_MAX_BODY_BYTES');
            } elseif ($defStore !== '' && (!ctype_digit($defStore) || (int) $defStore < 0)) {
                $err = Loc::getMessage('AS_ONECSTOCK_OPTIONS_ERR_DEFAULT_STORE_ID');
            }

            if ($err !== null) {
                CAdminMessage::ShowMessage(['MESSAGE' => $err, 'TYPE' => 'ERROR']);
                $stickyValuesAfterError = true;
            } else {
                Option::set($moduleId, 'max_items', $maxItems === '' ? '' : (string) max(1, (int) $maxItems));
                Option::set($moduleId, 'batch_size', $batchSize === '' ? '' : (string) max(1, (int) $batchSize));
                Option::set($moduleId, 'max_body_bytes', $maxBody === '' ? '' : (string) max(1024, (int) $maxBody));
                Option::set($moduleId, 'default_store_id', $defStore === '' ? '' : (string) max(0, (int) $defStore));
                CAdminMessage::ShowNote(Loc::getMessage('AS_ONECSTOCK_OPTIONS_SAVED'));
            }
        }
    }
}

$maxItems = Option::get($moduleId, 'max_items', '');
$batchSize = Option::get($moduleId, 'batch_size', '');
$maxBodyBytes = Option::get($moduleId, 'max_body_bytes', '');
$defaultStoreId = Option::get($moduleId, 'default_store_id', '');

if ($stickyValuesAfterError) {
    $maxItems = trim((string) $request->getPost('max_items'));
    $batchSize = trim((string) $request->getPost('batch_size'));
    $maxBodyBytes = trim((string) $request->getPost('max_body_bytes'));
    $defaultStoreId = trim((string) $request->getPost('default_store_id'));
}

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
<form method="post" action="<?= htmlspecialcharsbx($formAction) ?>">
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
    <input <?php if (!$canWrite) {
        echo 'disabled';
           } ?> type="submit" name="Update" value="<?= htmlspecialcharsbx(
               Loc::getMessage('AS_ONECSTOCK_OPTIONS_SAVE')
           ) ?>">
    <input <?php if (!$canWrite) {
        echo 'disabled';
           } ?> type="submit" name="RestoreDefaults" value="<?= htmlspecialcharsbx(
               Loc::getMessage('AS_ONECSTOCK_OPTIONS_RESTORE_DEFAULTS')
           ) ?>">
    <?php
    $tabControl->End();
    ?>
</form>
