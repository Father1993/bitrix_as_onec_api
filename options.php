<?php

/**
 * Настройки модуля для bitrix/admin/settings.php.
 *
 * Важно: при подключении из settings.php файл модуля выполняется ДО объявления в settings.php
 * функций __AdmSettingsDrawList / __AdmSettingsSaveOptions (см. ядро main/admin/settings.php),
 * поэтому вызывать их из options.php нельзя. Описание полей оформлено массивом по аналогии с gist/докой Bitrix.
 */

defined('B_PROLOG_INCLUDED') || die();

use Bitrix\Main\Config\Option;
use Bitrix\Main\HttpApplication;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/** @global CMain $APPLICATION */

$moduleId = 'as.onec_api';

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

/** @var list<array{id: string, label: string, size: int}> */
$arTextOptions = [
    ['id' => 'max_items', 'label' => Loc::getMessage('AS_ONEC_API_OPTIONS_MAX_ITEMS'), 'size' => 12],
    ['id' => 'batch_size', 'label' => Loc::getMessage('AS_ONEC_API_OPTIONS_BATCH_SIZE'), 'size' => 12],
    ['id' => 'max_body_bytes', 'label' => Loc::getMessage('AS_ONEC_API_OPTIONS_MAX_BODY_BYTES'), 'size' => 12],
    ['id' => 'default_store_id', 'label' => Loc::getMessage('AS_ONEC_API_OPTIONS_DEFAULT_STORE_ID'), 'size' => 12],
];

$validatePosted = static function (array $posted): ?string {
    $maxItems = $posted['max_items'] ?? '';
    $batchSize = $posted['batch_size'] ?? '';
    $maxBody = $posted['max_body_bytes'] ?? '';
    $defStore = $posted['default_store_id'] ?? '';

    if ($maxItems !== '' && (!ctype_digit($maxItems) || (int) $maxItems < 1)) {
        return Loc::getMessage('AS_ONEC_API_OPTIONS_ERR_MAX_ITEMS');
    }
    if ($batchSize !== '' && (!ctype_digit($batchSize) || (int) $batchSize < 1)) {
        return Loc::getMessage('AS_ONEC_API_OPTIONS_ERR_BATCH_SIZE');
    }
    if ($maxBody !== '' && (!ctype_digit($maxBody) || (int) $maxBody < 1024)) {
        return Loc::getMessage('AS_ONEC_API_OPTIONS_ERR_MAX_BODY_BYTES');
    }
    if ($defStore !== '' && (!ctype_digit($defStore) || (int) $defStore < 0)) {
        return Loc::getMessage('AS_ONEC_API_OPTIONS_ERR_DEFAULT_STORE_ID');
    }

    return null;
};

$normalizeForSave = static function (array $posted): array {
    $maxItems = trim((string) ($posted['max_items'] ?? ''));
    $batchSize = trim((string) ($posted['batch_size'] ?? ''));
    $maxBody = trim((string) ($posted['max_body_bytes'] ?? ''));
    $defStore = trim((string) ($posted['default_store_id'] ?? ''));

    return [
        'max_items' => $maxItems === '' ? '' : (string) max(1, (int) $maxItems),
        'batch_size' => $batchSize === '' ? '' : (string) max(1, (int) $batchSize),
        'max_body_bytes' => $maxBody === '' ? '' : (string) max(1024, (int) $maxBody),
        'default_store_id' => $defStore === '' ? '' : (string) max(0, (int) $defStore),
    ];
};

$stickyValuesAfterError = false;

if ($request->isPost() && $canWrite) {
    $isRestore = $request->getPost('RestoreDefaults') !== null;
    $isUpdate = $request->getPost('Update') !== null;

    if ($isRestore || $isUpdate) {
        if ($isRestore) {
            foreach (['max_items', 'batch_size', 'max_body_bytes', 'default_store_id'] as $name) {
                Option::delete($moduleId, ['name' => $name]);
            }
            CAdminMessage::ShowNote(Loc::getMessage('AS_ONEC_API_OPTIONS_SAVED'));
        } elseif ($isUpdate) {
            $posted = [];
            foreach ($arTextOptions as $opt) {
                $posted[$opt['id']] = trim((string) $request->getPost($opt['id']));
            }

            $err = $validatePosted($posted);
            if ($err !== null) {
                CAdminMessage::ShowMessage(['MESSAGE' => $err, 'TYPE' => 'ERROR']);
                $stickyValuesAfterError = true;
            } else {
                foreach ($normalizeForSave($posted) as $name => $value) {
                    Option::set($moduleId, $name, $value);
                }
                CAdminMessage::ShowNote(Loc::getMessage('AS_ONEC_API_OPTIONS_SAVED'));
            }
        }
    }
}

$values = [];
foreach ($arTextOptions as $opt) {
    $values[$opt['id']] = Option::get($moduleId, $opt['id'], '');
}

if ($stickyValuesAfterError) {
    foreach ($arTextOptions as $opt) {
        $values[$opt['id']] = trim((string) $request->getPost($opt['id']));
    }
}

$aTabs = [
    [
        'DIV' => 'as_onec_api',
        'TAB' => Loc::getMessage('AS_ONEC_API_OPTIONS_TAB'),
        'ICON' => '',
        'TITLE' => Loc::getMessage('AS_ONEC_API_OPTIONS_TAB_TITLE'),
    ],
];

$tabControl = new CAdminTabControl('tabControl', $aTabs);
?>
<form method="post" action="<?= htmlspecialcharsbx($formAction) ?>">
    <?php
    $tabControl->Begin();
    $tabControl->BeginNextTab();
    ?>
    <tr>
        <td colspan="2"><?= Loc::getMessage('AS_ONEC_API_OPTIONS_HINT_EMPTY') ?></td>
    </tr>
    <?php foreach ($arTextOptions as $opt) { ?>
    <tr>
        <td width="40%"><?= htmlspecialcharsbx($opt['label']) ?>:</td>
        <td width="60%"><input type="text" size="<?= (int) $opt['size'] ?>" name="<?= htmlspecialcharsbx(
            $opt['id']
        ) ?>" value="<?= htmlspecialcharsbx($values[$opt['id']]) ?>"></td>
    </tr>
    <?php } ?>
    <?php
    $tabControl->EndTab();
    $tabControl->Buttons();
    ?>
    <input <?php if (!$canWrite) {
        echo 'disabled';
           } ?> type="submit" name="Update" value="<?= htmlspecialcharsbx(
               Loc::getMessage('AS_ONEC_API_OPTIONS_SAVE')
           ) ?>">
    <input <?php if (!$canWrite) {
        echo 'disabled';
           } ?> type="submit" name="RestoreDefaults" value="<?= htmlspecialcharsbx(
               Loc::getMessage('AS_ONEC_API_OPTIONS_RESTORE_DEFAULTS')
           ) ?>">
    <?php
    $tabControl->End();
    ?>
</form>
