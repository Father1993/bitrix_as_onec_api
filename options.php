<?php

/**
 * Настройки модуля для bitrix/admin/settings.php.
 *
 * Сохранение (POST): только при GetGroupRight >= W на модуль. check_bitrix_sessid() не используется —
 * иначе при вложенном рендере формы через ядро settings.php возможен ложный отказ; защита — права на модуль.
 *
 * При подключении из settings.php файл выполняется ДО объявления __AdmSettingsDrawList / __AdmSettingsSaveOptions
 * (см. main/admin/settings.php), поэтому вызывать их из options.php нельзя. Поля задаются массивом, как в типовых partner-модулях.
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

/** @var list<array{id: string, label: string, rows: int}> */
$arTextareaOptions = [
    ['id' => 'order_status_map_json', 'label' => Loc::getMessage('AS_ONEC_API_OPTIONS_ORDER_STATUS_MAP_JSON'), 'rows' => 8],
    ['id' => 'order_status_allowed_transitions_json', 'label' => Loc::getMessage('AS_ONEC_API_OPTIONS_ORDER_STATUS_ALLOWED_TRANSITIONS_JSON'), 'rows' => 8],
];

/** @var list<array{id: string, label: string}> */
$arCheckboxOptions = [
    ['id' => 'order_status_sync_payment', 'label' => Loc::getMessage('AS_ONEC_API_OPTIONS_ORDER_STATUS_SYNC_PAYMENT')],
    ['id' => 'order_status_sync_shipment', 'label' => Loc::getMessage('AS_ONEC_API_OPTIONS_ORDER_STATUS_SYNC_SHIPMENT')],
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

    foreach (['order_status_map_json', 'order_status_allowed_transitions_json'] as $jsonField) {
        $raw = trim((string) ($posted[$jsonField] ?? ''));
        if ($raw === '') {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $jsonField === 'order_status_map_json'
                ? Loc::getMessage('AS_ONEC_API_OPTIONS_ERR_ORDER_STATUS_MAP_JSON')
                : Loc::getMessage('AS_ONEC_API_OPTIONS_ERR_ORDER_STATUS_ALLOWED_TRANSITIONS_JSON');
        }
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
        'order_status_map_json' => trim((string) ($posted['order_status_map_json'] ?? '')),
        'order_status_allowed_transitions_json' => trim((string) ($posted['order_status_allowed_transitions_json'] ?? '')),
        'order_status_sync_payment' => !empty($posted['order_status_sync_payment']) ? 'Y' : 'N',
        'order_status_sync_shipment' => !empty($posted['order_status_sync_shipment']) ? 'Y' : 'N',
    ];
};

$stickyValuesAfterError = false;

if ($request->isPost() && $canWrite) {
    $isRestore = $request->getPost('RestoreDefaults') !== null;
    $isUpdate = $request->getPost('Update') !== null;

    if ($isRestore || $isUpdate) {
        if ($isRestore) {
            foreach ([
                'max_items',
                'batch_size',
                'max_body_bytes',
                'default_store_id',
                'order_status_map_json',
                'order_status_allowed_transitions_json',
                'order_status_sync_payment',
                'order_status_sync_shipment',
            ] as $name) {
                Option::delete($moduleId, ['name' => $name]);
            }
            CAdminMessage::ShowNote(Loc::getMessage('AS_ONEC_API_OPTIONS_SAVED'));
        } elseif ($isUpdate) {
            $posted = [];
            foreach ($arTextOptions as $opt) {
                $posted[$opt['id']] = trim((string) $request->getPost($opt['id']));
            }
            foreach ($arTextareaOptions as $opt) {
                $posted[$opt['id']] = trim((string) $request->getPost($opt['id']));
            }
            foreach ($arCheckboxOptions as $opt) {
                $posted[$opt['id']] = $request->getPost($opt['id']) === 'Y' ? 'Y' : 'N';
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
foreach ($arTextareaOptions as $opt) {
    $values[$opt['id']] = Option::get($moduleId, $opt['id'], '');
}
foreach ($arCheckboxOptions as $opt) {
    $values[$opt['id']] = Option::get($moduleId, $opt['id'], 'N');
}

if ($stickyValuesAfterError) {
    foreach ($arTextOptions as $opt) {
        $values[$opt['id']] = trim((string) $request->getPost($opt['id']));
    }
    foreach ($arTextareaOptions as $opt) {
        $values[$opt['id']] = trim((string) $request->getPost($opt['id']));
    }
    foreach ($arCheckboxOptions as $opt) {
        $values[$opt['id']] = $request->getPost($opt['id']) === 'Y' ? 'Y' : 'N';
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
    <?php foreach ($arTextareaOptions as $opt) { ?>
    <tr>
        <td width="40%" style="vertical-align: top;"><?= htmlspecialcharsbx($opt['label']) ?>:</td>
        <td width="60%">
            <textarea name="<?= htmlspecialcharsbx($opt['id']) ?>" rows="<?= (int) $opt['rows'] ?>" cols="70"><?= htmlspecialcharsbx(
                $values[$opt['id']]
            ) ?></textarea>
        </td>
    </tr>
    <?php } ?>
    <?php foreach ($arCheckboxOptions as $opt) { ?>
    <tr>
        <td width="40%"><?= htmlspecialcharsbx($opt['label']) ?>:</td>
        <td width="60%">
            <label>
                <input type="checkbox" name="<?= htmlspecialcharsbx($opt['id']) ?>" value="Y" <?php if (($values[$opt['id']] ?? 'N') === 'Y') {
                    echo 'checked';
                } ?>>
                <?= htmlspecialcharsbx(Loc::getMessage('AS_ONEC_API_OPTIONS_YES')) ?>
            </label>
        </td>
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
