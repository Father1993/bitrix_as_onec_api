<?php

/**
 * Импорт остатков из 1С в каталог (п. 2.1 ТЗ).
 * Модуль: as.onec_api. Публичный приём данных — HTTP POST (JSON), см. public/http_import.php и
 * /local/tools/as_onec_api.php. Лимиты запроса: настройки модуля (options.php) с fallback на
 * константы ONEC_STOCK_IMPORT_*.
 *
 * Контракт JSON (один из вариантов тела):
 * - Массив: [ { "product_xml_id": "...", "amount": 12.5, "store_id": 1 }, ... ]
 * - Объект: { "items": [ ... ], "access_key": "..." (если не в заголовке) }
 *
 * Поля строки:
 * - product_xml_id | xml_id — внешний код элемента (как PRODUCT_XML_ID в заказах)
 * - product_id — опционально, ID элемента каталога (если задан, XML не ищем)
 * - amount | quantity — количество (>= 0)
 * - store_id — ID склада Битрикс (при складском учёте)
 * - store_xml_id | store_code — внешний код склада: XML_ID из b_catalog_store, либо тот же UUID, что у значения
 *   списка «_Место хранения» (MESTO_KHRANENIYA, ИБ торговых предложений) — см. import.xml / админку свойства
 *
 * Авторизация (достаточно одного способа):
 * - Заголовок X-Stock-Import-Key: <ONEC_STOCK_IMPORT_ACCESS_KEY>
 * - Поле access_key в корне JSON (если объект)
 * - login + password в корне JSON — как в orders_export_to_1c.php ($USER->Login)
 *
 * Внутренний вызов: можно передать ['payload' => array, 'trust_bitrix_auth' => true], если тело уже
 * разобрано и сессия пользователя уже доверена (например, внутренний сценарий после prolog).
 *
 * Чтение остатков по XML_ID: {@see \As\OnecApi\Http\JsonApiKernel}, {@see \As\OnecApi\Stock\StockReadService};
 * общая проверка ключа: {@see asStockApiAuthBySecretKey()}.
 *
 * @noinspection PhpUndefinedClassInspection
 */

use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;
use As\OnecApi\StockImportOptions;

/**
 * Точка входа для старого кода и агентов: делегирует в {@see \As\OnecApi\Stock\ImportService::run()}.
 *
 * @param array{payload?:array, trust_bitrix_auth?:bool} $options
 * @return array{http_code:int, data:array}
 */
function asStockImportFrom1cRun(array $options = []): array
{
    return \As\OnecApi\Stock\ImportService::run($options);
}

/**
 * Проверка секретного ключа: заголовок X-Stock-Import-Key и опционально строка access_key (тело JSON или GET).
 * Для GET не используйте логин/пароль в URL — только ключ.
 *
 * @param string|null $accessKeyFromBodyOrQuery значение access_key
 * @return array{ok:bool, message?:string}
 */
function asStockApiAuthBySecretKey(?string $accessKeyFromBodyOrQuery = null): array
{
    if (defined('ONEC_STOCK_IMPORT_SKIP_AUTH') && ONEC_STOCK_IMPORT_SKIP_AUTH) {
        return ['ok' => true];
    }

    if (!defined('ONEC_STOCK_IMPORT_ACCESS_KEY')) {
        return [
            'ok' => false,
            'message' => 'Не задан ONEC_STOCK_IMPORT_ACCESS_KEY в config.php.',
        ];
    }

    $expectedKey = (string) ONEC_STOCK_IMPORT_ACCESS_KEY;
    $key = isset($_SERVER['HTTP_X_STOCK_IMPORT_KEY']) ? trim((string) $_SERVER['HTTP_X_STOCK_IMPORT_KEY']) : '';
    if ($key !== '' && strlen($key) === strlen($expectedKey) && hash_equals($expectedKey, $key)) {
        return ['ok' => true];
    }

    if ($accessKeyFromBodyOrQuery !== null) {
        $ak = trim((string) $accessKeyFromBodyOrQuery);
        if ($ak !== '' && strlen($ak) === strlen($expectedKey) && hash_equals($expectedKey, $ak)) {
            return ['ok' => true];
        }
    }

    return [
        'ok' => false,
        'message' => 'Неверный или отсутствующий ключ доступа (X-Stock-Import-Key или access_key).',
    ];
}

/**
 * @param mixed $decoded
 * @return array{ok:bool, message?:string}
 */
function asStockImportFrom1cAuth($decoded): array
{
    $bodyKey = null;
    if (is_array($decoded) && array_key_exists('access_key', $decoded) && $decoded['access_key'] !== '') {
        $bodyKey = (string) $decoded['access_key'];
    }

    $byKey = asStockApiAuthBySecretKey($bodyKey);
    if ($byKey['ok']) {
        return ['ok' => true];
    }

    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => 'Неверный формат для авторизации.'];
    }

    $login = isset($decoded['login']) ? (string) $decoded['login'] : '';
    $password = isset($decoded['password']) ? (string) $decoded['password'] : '';
    if ($login === '' || $password === '') {
        return [
            'ok' => false,
            'message' => 'Нужен ключ X-Stock-Import-Key, поле access_key или login/password.',
        ];
    }

    global $USER;
    if (!is_object($USER)) {
        $USER = new \CUser();
    }

    $authResult = $USER->Login($login, $password, 'N');
    if ($authResult === true || $authResult === 1) {
        return ['ok' => true];
    }

    return ['ok' => false, 'message' => 'Неверный логин или пароль.'];
}

/**
 * @param mixed $decoded
 * @return array<int, array<string, mixed>>|null
 */
function asStockImportFrom1cNormalizeItems($decoded): ?array
{
    $items = asStockImportFrom1cExtractItems($decoded);
    if ($items === null) {
        return null;
    }

    $out = [];
    foreach ($items as $row) {
        $validated = asStockImportFrom1cValidateItem($row);
        if (!$validated['ok']) {
            continue;
        }

        $out[] = $validated['row'];
    }

    return $out === [] ? null : $out;
}

/**
 * @param mixed $decoded
 * @return array<int, mixed>|null
 */
function asStockImportFrom1cExtractItems($decoded): ?array
{
    if (is_array($decoded) && array_keys($decoded) !== range(0, count($decoded) - 1)) {
        if (!isset($decoded['items']) || !is_array($decoded['items'])) {
            return null;
        }
        $items = $decoded['items'];
    } elseif (is_array($decoded)) {
        $items = $decoded;
    } else {
        return null;
    }

    if ($items === []) {
        return null;
    }

    return $items;
}

/**
 * @param mixed $row
 * @return array{ok:true,row:array<string,mixed>}|array{ok:false,message:string}
 */
function asStockImportFrom1cValidateItem($row): array
{
    if (!is_array($row)) {
        return ['ok' => false, 'message' => 'Позиция должна быть объектом JSON.'];
    }

    $xml =
        $row['product_xml_id']
        ?? $row['xml_id']
        ?? $row['XML_ID']
        ?? '';
    $xml = is_string($xml) ? trim($xml) : '';

    $pid = 0;
    if (array_key_exists('product_id', $row) && $row['product_id'] !== '' && $row['product_id'] !== null) {
        if (!is_numeric($row['product_id'])) {
            return ['ok' => false, 'message' => 'Поле product_id должно быть числом.'];
        }
        $pid = (int) $row['product_id'];
        if ($pid <= 0) {
            return ['ok' => false, 'message' => 'Поле product_id должно быть положительным числом.'];
        }
    }

    $amount = $row['amount'] ?? $row['quantity'] ?? null;
    if ($amount === null || $amount === '') {
        return ['ok' => false, 'message' => 'Поле amount (или quantity) обязательно.'];
    }
    if (!is_numeric($amount)) {
        return ['ok' => false, 'message' => 'Поле amount должно быть числом.'];
    }
    $amount = (float) $amount;
    if ($amount < 0) {
        return ['ok' => false, 'message' => 'Поле amount не может быть отрицательным.'];
    }

    $storeId = 0;
    if (array_key_exists('store_id', $row) && $row['store_id'] !== '' && $row['store_id'] !== null) {
        if (!is_numeric($row['store_id'])) {
            return ['ok' => false, 'message' => 'Поле store_id должно быть числом.'];
        }
        $storeId = (int) $row['store_id'];
        if ($storeId < 0) {
            return ['ok' => false, 'message' => 'Поле store_id не может быть отрицательным.'];
        }
    }

    $storeXml = $row['store_xml_id'] ?? $row['store_code'] ?? '';
    $storeXml = is_string($storeXml) ? trim($storeXml) : '';

    if ($pid <= 0 && $xml === '') {
        return ['ok' => false, 'message' => 'Нужен product_id или product_xml_id/xml_id/XML_ID.'];
    }

    return [
        'ok' => true,
        'row' => [
            'product_xml_id' => $xml,
            'product_id' => $pid,
            'amount' => $amount,
            'store_id' => $storeId,
            'store_xml_id' => $storeXml,
        ],
    ];
}

/**
 * @return int[]
 */
function asStockImportFrom1cGetCatalogIblockIds(): array
{
    $ids = [IBLOCK_CATALOG];
    if (class_exists('\CCatalogSku')) {
        $sku = \CCatalogSku::GetInfoByProductIBlock(IBLOCK_CATALOG);
        if (!empty($sku['IBLOCK_ID'])) {
            $ids[] = (int) $sku['IBLOCK_ID'];
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

/**
 * @param array<string, mixed> $row
 * @param int[] $catalogIblockIds
 * @return array{ok:bool, message?:string, product_id?:int}
 *
 * Запись остатков: {@see StoreProductTable} (склады) / {@see ProductTable} (без складов). После батча при складах —
 * {@see \CCatalogStore::recalculateProductsBalances} в {@see asStockImportFrom1cRun}.
 */
function asStockImportFrom1cApplyRow(array $row, bool $useStores, array $catalogIblockIds): array
{
    $productId = (int) ($row['product_id'] ?? 0);
    if ($productId <= 0) {
        $xml = (string) ($row['product_xml_id'] ?? '');
        $resolved = asStockImportFrom1cResolveElementByXml($xml, $catalogIblockIds);
        if (!$resolved['ok']) {
            return ['ok' => false, 'message' => $resolved['message']];
        }
        $productId = $resolved['id'];
    }

    $productRow = ProductTable::getList([
        'filter' => ['=ID' => $productId],
        'select' => ['ID'],
        'limit' => 1,
    ])->fetch();
    if (!$productRow) {
        return ['ok' => false, 'message' => 'Нет торговой позиции catalog для этого ID.'];
    }

    if ($useStores) {
        $storeId = (int) ($row['store_id'] ?? 0);
        if ($storeId <= 0 && !empty($row['store_xml_id'])) {
            $storeId = asStockImportFrom1cResolveStoreId((string) $row['store_xml_id']);
        }
        $defaultStoreId = StockImportOptions::getDefaultStoreId();
        if ($storeId <= 0 && $defaultStoreId > 0) {
            $storeId = $defaultStoreId;
        }
        if ($storeId <= 0) {
            return ['ok' => false, 'message' => 'Не указан склад (store_id или store_xml_id) при включённом складском учёте.'];
        }

        $storeExists = StoreTable::getList([
            'filter' => ['=ID' => $storeId, '=ACTIVE' => 'Y'],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();
        if (!$storeExists) {
            return ['ok' => false, 'message' => 'Склад не найден или неактивен.'];
        }

        $amount = $row['amount'];
        $existing = StoreProductTable::getList([
            'filter' => [
                '=PRODUCT_ID' => $productId,
                '=STORE_ID' => $storeId,
            ],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();
        if ($existing) {
            $spRes = StoreProductTable::update((int) $existing['ID'], ['AMOUNT' => $amount]);
        } else {
            $spRes = StoreProductTable::add([
                'PRODUCT_ID' => $productId,
                'STORE_ID' => $storeId,
                'AMOUNT' => $amount,
            ]);
        }
        if (!$spRes->isSuccess()) {
            return [
                'ok' => false,
                'message' => 'Не удалось записать остаток по складу: ' . implode('; ', $spRes->getErrorMessages()),
            ];
        }

        return ['ok' => true, 'product_id' => $productId];
    }

    $pRes = \Bitrix\Catalog\Model\Product::update($productId, ['QUANTITY' => $row['amount']]);
    if (!$pRes->isSuccess()) {
        return [
            'ok' => false,
            'message' => 'Не удалось обновить QUANTITY: ' . implode('; ', $pRes->getErrorMessages()),
        ];
    }

    return ['ok' => true, 'product_id' => $productId];
}

function asStockImportFrom1cResolveElementIdByXml(string $xmlId, array $catalogIblockIds): int
{
    $resolved = asStockImportFrom1cResolveElementByXml($xmlId, $catalogIblockIds);

    return !empty($resolved['ok']) ? (int) $resolved['id'] : 0;
}

/**
 * @param int[] $catalogIblockIds
 * @return array{ok:true,id:int}|array{ok:false,message:string}
 */
function asStockImportFrom1cResolveElementByXml(string $xmlId, array $catalogIblockIds): array
{
    if ($xmlId === '' || $catalogIblockIds === []) {
        return ['ok' => false, 'message' => 'Элемент с указанным XML_ID не найден в каталоге.'];
    }

    $rows = ElementTable::getList([
        'filter' => [
            '=XML_ID' => $xmlId,
            '@IBLOCK_ID' => $catalogIblockIds,
        ],
        'select' => ['ID'],
        'order' => ['ID' => 'ASC'],
        'limit' => 2,
    ])->fetchAll();

    if ($rows === []) {
        return ['ok' => false, 'message' => 'Элемент с указанным XML_ID не найден в каталоге.'];
    }

    if (count($rows) > 1) {
        return ['ok' => false, 'message' => 'Найдено несколько элементов с одинаковым XML_ID. Импорт для этой позиции остановлен.'];
    }

    return ['ok' => true, 'id' => (int) $rows[0]['ID']];
}

function asStockImportFrom1cResolveStoreId(string $xmlOrCode): int
{
    if ($xmlOrCode === '') {
        return 0;
    }

    $candidates = array_unique(array_filter([
        $xmlOrCode,
        mb_strtolower($xmlOrCode) !== $xmlOrCode ? mb_strtolower($xmlOrCode) : null,
    ]));

    foreach ($candidates as $candidate) {
        foreach (['XML_ID', 'CODE'] as $field) {
            $row = StoreTable::getList([
                'filter' => [
                    '=' . $field => $candidate,
                    '=ACTIVE' => 'Y',
                ],
                'select' => ['ID'],
                'limit' => 1,
            ])->fetch();
            if ($row) {
                return (int) $row['ID'];
            }
        }
        $mesto = asStockImportFrom1cResolveStoreIdViaMestoKhstorageEnum($candidate);
        if ($mesto > 0) {
            return $mesto;
        }
    }

    return 0;
}

/**
 * Соответствие UUID из списка «_Место хранения» (как в CommerceML / import.xml) записям складов каталога.
 * Сначала склад с тем же XML_ID, иначе — по названию значения списка (= TITLE склада в админке).
 */
function asStockImportFrom1cResolveStoreIdViaMestoKhstorageEnum(string $enumXmlId): int
{
    if ($enumXmlId === '' || !defined('IBLOCK_CATALOG_OFFERS') || IBLOCK_CATALOG_OFFERS <= 0) {
        return 0;
    }

    $propertyId = asStockImportFrom1cGetMestoKhstoragePropertyId();
    if ($propertyId <= 0) {
        return 0;
    }

    $enum = null;
    foreach ([$enumXmlId, mb_strtolower($enumXmlId)] as $tryXml) {
        $enum = PropertyEnumerationTable::getList([
            'filter' => [
                '=PROPERTY_ID' => $propertyId,
                '=XML_ID' => $tryXml,
            ],
            'select' => ['ID', 'VALUE', 'XML_ID'],
            'limit' => 1,
        ])->fetch();
        if ($enum) {
            break;
        }
    }

    if (!$enum) {
        return 0;
    }

    $enumTitle = trim((string) ($enum['VALUE'] ?? ''));

    if ($enumTitle !== '') {
        $byTitle = StoreTable::getList([
            'filter' => [
                '=TITLE' => $enumTitle,
                '=ACTIVE' => 'Y',
            ],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();
        if ($byTitle) {
            return (int) $byTitle['ID'];
        }
    }

    return 0;
}

/**
 * ID свойства списка MESTO_KHRANENIYA (кэш на запрос).
 */
function asStockImportFrom1cGetMestoKhstoragePropertyId(): int
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $cached = 0;

    if (defined('IBLOCK_PROPERTY_MESTO_KHRANENIYA_ID') && (int) IBLOCK_PROPERTY_MESTO_KHRANENIYA_ID > 0) {
        $cached = (int) IBLOCK_PROPERTY_MESTO_KHRANENIYA_ID;

        return $cached;
    }

    $code = defined('IBLOCK_PROPERTY_MESTO_KHRANENIYA_CODE')
        ? (string) IBLOCK_PROPERTY_MESTO_KHRANENIYA_CODE
        : 'MESTO_KHRANENIYA';

    $row = PropertyTable::getList([
        'filter' => [
            '=IBLOCK_ID' => (int) IBLOCK_CATALOG_OFFERS,
            '=CODE' => $code,
        ],
        'select' => ['ID'],
        'limit' => 1,
    ])->fetch();

    $cached = $row ? (int) $row['ID'] : 0;

    return $cached;
}

/**
 * @param array<string, int|string> $context
 */
function asStockImportFrom1cLog(string $event, array $context = []): void
{
    $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($docRoot === '') {
        return;
    }
    $dir = rtrim($docRoot, '/\\') . '/upload/logs';
    if (!is_dir($dir)) {
        if (function_exists('CheckDirPath')) {
            CheckDirPath($dir . '/');
        }
        if (!is_dir($dir)) {
            $perm = defined('BX_DIR_PERMISSIONS') ? (int) BX_DIR_PERMISSIONS : 0755;
            mkdir($dir, $perm, true);
        }
        if (!is_dir($dir)) {
            return;
        }
    }
    if (!is_writable($dir)) {
        return;
    }
    $file = $dir . '/as_1c_stock_import_' . date('Y-m-d') . '.log';
    $line = date('c') . "\t" . $event . "\t" . json_encode($context, JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
