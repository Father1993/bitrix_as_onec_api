<?php

use As\OnecApi\Audit\AuditService;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Filter\Options as FilterOptions;
use Bitrix\Main\UI\PageNavigation;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$moduleId = 'as.onec_api';
if (!Loader::includeModule($moduleId)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    echo 'Модуль as.onec_api не установлен.';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

global $APPLICATION;
$right = $APPLICATION->GetGroupRight($moduleId);
if ($right < 'R') {
    $APPLICATION->AuthForm('Недостаточно прав для просмотра журнала обмена.');
}

$gridId = 'AS_ONEC_API_AUDIT_GRID';
$filterId = 'AS_ONEC_API_AUDIT_FILTER';
$filterFields = [
    ['id' => 'REQUEST_ID', 'name' => 'ID запроса'],
    ['id' => 'OPERATION', 'name' => 'Операция', 'type' => 'list', 'items' => [
        'stocks_import' => 'Остатки', 'prices_import' => 'Цены', 'stores_write' => 'Склады', 'orders_status_import' => 'Статусы заказов',
    ]],
    ['id' => 'STATUS', 'name' => 'Статус', 'type' => 'list', 'items' => [
        'success' => 'Успех', 'partial' => 'Частичная ошибка', 'error' => 'Ошибка',
    ]],
];
$filterData = (new FilterOptions($filterId))->getFilter($filterFields);
$filter = [];
if (($value = trim((string) ($filterData['REQUEST_ID'] ?? ''))) !== '') {
    $filter['=REQUEST_ID'] = $value;
}
if (($value = trim((string) ($filterData['OPERATION'] ?? ''))) !== '') {
    $filter['=OPERATION'] = $value;
}
if (($value = trim((string) ($filterData['STATUS'] ?? ''))) !== '') {
    $filter['=STATUS'] = $value;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_audit') {
    if ($right < 'W') {
        $message = 'Для очистки журнала нужны права на запись в модуль.';
    } elseif (!check_bitrix_sessid()) {
        $message = 'Сессия истекла. Обновите страницу и повторите действие.';
    } else {
        $deleted = AuditService::clear($filter);
        $message = 'Удалено записей: ' . $deleted . '.';
    }
}

$navigation = new PageNavigation('as_onec_api_audit_nav');
$navigation->allowAllRecords(false);
$navigation->setPageSize(50);
$navigation->initFromUri();
$navigation->setRecordCount(AuditService::count($filter));
$records = AuditService::find($filter, $navigation->getLimit(), $navigation->getOffset());
$rows = [];
foreach ($records as $record) {
    $details = [
        'request' => $record['request'] ?? [],
        'response' => $record['response'] ?? [],
        'errors' => $record['errors'] ?? [],
    ];
    $detailJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
    $rows[] = [
        'id' => (int) $record['id'],
        'data' => [
            'CREATED_AT' => (string) $record['at'],
            'REQUEST_ID' => (string) $record['request_id'],
            'OPERATION' => (string) $record['operation'],
            'STATUS' => (string) $record['status'],
            'HTTP_CODE' => (int) $record['http_code'],
            'COUNTS' => sprintf('total=%s, updated=%s, failed=%s, no_change=%s', $record['total'] ?? '-', $record['updated'] ?? '-', $record['failed'] ?? '-', $record['no_change'] ?? '-'),
        ],
        'columns' => [
            'DETAILS' => '<details><summary>Раскрыть</summary><pre style="max-width:760px;max-height:360px;overflow:auto;white-space:pre-wrap">' . htmlspecialcharsbx($detailJson) . '</pre></details>',
        ],
    ];
}

$APPLICATION->SetTitle('Журнал обмена 1С');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
if ($message !== '') {
    CAdminMessage::ShowMessage($message);
}
$APPLICATION->IncludeComponent('bitrix:main.ui.filter', '', [
    'FILTER_ID' => $filterId,
    'GRID_ID' => $gridId,
    'FILTER' => $filterFields,
    'ENABLE_LIVE_SEARCH' => false,
    'ENABLE_LABEL' => true,
]);
$APPLICATION->IncludeComponent('bitrix:main.ui.grid', '', [
    'GRID_ID' => $gridId,
    'COLUMNS' => [
        ['id' => 'CREATED_AT', 'name' => 'Время', 'default' => true],
        ['id' => 'REQUEST_ID', 'name' => 'ID запроса', 'default' => true],
        ['id' => 'OPERATION', 'name' => 'Операция', 'default' => true],
        ['id' => 'STATUS', 'name' => 'Статус', 'default' => true],
        ['id' => 'HTTP_CODE', 'name' => 'HTTP', 'default' => true],
        ['id' => 'COUNTS', 'name' => 'Счётчики', 'default' => true],
        ['id' => 'DETAILS', 'name' => 'Детали', 'default' => true],
    ],
    'ROWS' => $rows,
    'NAV_OBJECT' => $navigation,
    'TOTAL_ROWS_COUNT' => $navigation->getRecordCount(),
    'PAGE_SIZES' => [['NAME' => '50', 'VALUE' => '50']],
    'SHOW_ROW_CHECKBOXES' => false,
    'SHOW_NAVIGATION_PANEL' => true,
    'SHOW_PAGINATION' => true,
    'SHOW_TOTAL_COUNTER' => true,
    'SHOW_PAGESIZE' => false,
    'SHOW_GRID_SETTINGS_MENU' => true,
    'AJAX_MODE' => 'Y',
    'AJAX_OPTION_JUMP' => 'N',
    'AJAX_OPTION_HISTORY' => 'N',
]);
if ($right >= 'W') { ?>
    <form method="post" style="margin-top: 16px;" onsubmit="return confirm('Удалить записи журнала с текущим фильтром? Это действие нельзя отменить.');">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="clear_audit">
        <?php foreach (['REQUEST_ID', 'OPERATION', 'STATUS'] as $field) { ?>
            <input type="hidden" name="<?= htmlspecialcharsbx($field) ?>" value="<?= htmlspecialcharsbx((string) ($filterData[$field] ?? '')) ?>">
        <?php } ?>
        <input type="submit" class="adm-btn-save" value="Очистить записи по текущему фильтру">
    </form>
<?php }
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
