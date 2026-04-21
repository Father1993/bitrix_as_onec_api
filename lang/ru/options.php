<?php

$MESS['AS_ONEC_API_OPTIONS_TAB'] = 'API обмена с 1С (остатки, лимиты)';
$MESS['AS_ONEC_API_OPTIONS_TAB_TITLE'] = 'Лимиты и параметры HTTP-импорта';
$MESS['AS_ONEC_API_OPTIONS_HINT_EMPTY'] =
    'Пустые поля: используются константы ONEC_STOCK_IMPORT_* из local/php_interface/include/config.php (если заданы), иначе значения по умолчанию модуля.';
$MESS['AS_ONEC_API_OPTIONS_MAX_ITEMS'] = 'Максимум позиций в одном запросе';
$MESS['AS_ONEC_API_OPTIONS_BATCH_SIZE'] = 'Размер пакета при обработке';
$MESS['AS_ONEC_API_OPTIONS_MAX_BODY_BYTES'] = 'Максимальный размер тела запроса (байт)';
$MESS['AS_ONEC_API_OPTIONS_DEFAULT_STORE_ID'] = 'Склад по умолчанию (ID), если не указан в строке';
$MESS['AS_ONEC_API_OPTIONS_ORDER_STATUS_MAP_JSON'] = 'JSON-мэппинг статусов 1С -> Bitrix (например {"paid":{"status_id":"P","paid":true}})';
$MESS['AS_ONEC_API_OPTIONS_ORDER_STATUS_ALLOWED_TRANSITIONS_JSON'] = 'JSON-разрешения переходов статусов Bitrix (например {"N":["P","F"]})';
$MESS['AS_ONEC_API_OPTIONS_ORDER_STATUS_SYNC_PAYMENT'] = 'Разрешить API менять оплату заказа';
$MESS['AS_ONEC_API_OPTIONS_ORDER_STATUS_SYNC_SHIPMENT'] = 'Разрешить API менять отгрузку заказа';
$MESS['AS_ONEC_API_OPTIONS_SAVE'] = 'Сохранить';
$MESS['AS_ONEC_API_OPTIONS_YES'] = 'Да';
$MESS['AS_ONEC_API_OPTIONS_RESTORE_DEFAULTS'] = 'По умолчанию';
$MESS['AS_ONEC_API_OPTIONS_SAVED'] = 'Настройки сохранены.';
$MESS['AS_ONEC_API_OPTIONS_ERR_SESSID'] =
    'Сессия истекла или неверный ключ безопасности. Обновите страницу и сохраните снова.';
$MESS['AS_ONEC_API_OPTIONS_ERR_MAX_ITEMS'] =
    '«Максимум позиций»: укажите пусто (по константам) или целое число не меньше 1.';
$MESS['AS_ONEC_API_OPTIONS_ERR_BATCH_SIZE'] =
    '«Размер пакета»: пусто или целое число не меньше 1.';
$MESS['AS_ONEC_API_OPTIONS_ERR_MAX_BODY_BYTES'] =
    '«Максимальный размер тела»: пусто или целое число байт не меньше 1024.';
$MESS['AS_ONEC_API_OPTIONS_ERR_DEFAULT_STORE_ID'] =
    '«Склад по умолчанию»: пусто или неотрицательное целое число (ID склада).';
$MESS['AS_ONEC_API_OPTIONS_ERR_ORDER_STATUS_MAP_JSON'] =
    '«JSON-мэппинг статусов»: укажите пусто или корректный JSON-объект.';
$MESS['AS_ONEC_API_OPTIONS_ERR_ORDER_STATUS_ALLOWED_TRANSITIONS_JSON'] =
    '«JSON-разрешения переходов»: укажите пусто или корректный JSON-объект.';
