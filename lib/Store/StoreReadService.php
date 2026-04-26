<?php

namespace As\OnecApi\Store;

use As\OnecApi\Http\JsonResponse;
use Bitrix\Catalog\StoreTable;
use Bitrix\Main\Loader;

/**
 * Справочник складов (GET JSON): b_catalog_store через {@see StoreTable}.
 */
final class StoreReadService
{
    private const MAX_LIST_ROWS = 500;

    /**
     * @param array<string, mixed> $get query ($_GET)
     * @return array{http_code:int, data:array}
     */
    public static function query(array $get): array
    {
        if (!Loader::includeModule('catalog')) {
            return [
                'http_code' => 500,
                'data' => [
                    'ok' => false,
                    'error' => 'MODULES',
                    'message' => 'Не подключён модуль catalog.',
                ],
            ];
        }

        $includeInactive = self::isTruthy($get['include_inactive'] ?? null);

        $storeId = isset($get['store_id']) && $get['store_id'] !== '' && $get['store_id'] !== null
            ? (int) $get['store_id'] : 0;
        $xmlId = self::strParam($get, ['store_xml_id', 'store_xmlid', 'xml_id', 'XML_ID']);
        $code = self::strParam($get, ['code', 'store_code', 'store_code_1c']);

        if ($storeId > 0) {
            $filter = self::baseFilter($includeInactive);
            $filter['=ID'] = $storeId;
            $row = self::fetchOne($filter);
            if ($row === null) {
                return [
                    'http_code' => 404,
                    'data' => [
                        'ok' => false,
                        'error' => 'NOT_FOUND',
                        'message' => 'Склад не найден по store_id.',
                        'api_version' => JsonResponse::API_VERSION,
                    ],
                ];
            }

            return [
                'http_code' => 200,
                'data' => self::okPayload([self::rowToJson($row)], 1, $includeInactive),
            ];
        }

        if ($xmlId !== '' || $code !== '') {
            $filter = self::baseFilter($includeInactive);
            if ($xmlId !== '') {
                $filter['=XML_ID'] = $xmlId;
            } else {
                $filter['=CODE'] = $code;
            }

            $rows = self::fetchList($filter, 3);
            if (count($rows) === 0) {
                $msg = $xmlId !== '' ? 'Склад не найден по store_xml_id / xml_id.' : 'Склад не найден по code.';

                return [
                    'http_code' => 404,
                    'data' => [
                        'ok' => false,
                        'error' => 'NOT_FOUND',
                        'message' => $msg,
                        'api_version' => JsonResponse::API_VERSION,
                    ],
                ];
            }
            if (count($rows) > 1) {
                return [
                    'http_code' => 409,
                    'data' => [
                        'ok' => false,
                        'error' => 'AMBIGUOUS',
                        'message' => 'Найдено несколько записей, уточните store_id.',
                        'api_version' => JsonResponse::API_VERSION,
                    ],
                ];
            }

            return [
                'http_code' => 200,
                'data' => self::okPayload([self::rowToJson($rows[0])], 1, $includeInactive),
            ];
        }

        $filter = self::baseFilter($includeInactive);
        $list = self::fetchList($filter, self::MAX_LIST_ROWS);
        $payload = self::okPayload(
            array_map(
                static function (array $r): array {
                    return self::rowToJson($r);
                },
                $list
            ),
            count($list),
            $includeInactive
        );
        if (count($list) >= self::MAX_LIST_ROWS) {
            $payload['truncated'] = true;
            $payload['max_rows'] = self::MAX_LIST_ROWS;
        }

        return ['http_code' => 200, 'data' => $payload];
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseFilter(bool $includeInactive): array
    {
        $f = [];
        if (!$includeInactive) {
            $f['=ACTIVE'] = 'Y';
        }

        return $f;
    }

    /**
     * @param array<string, mixed> $get
     * @param list<string> $keys
     */
    private static function strParam(array $get, array $keys): string
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $get)) {
                continue;
            }
            $v = $get[$k];
            if ($v === null) {
                continue;
            }
            $s = is_string($v) ? trim($v) : trim((string) $v);
            if ($s !== '') {
                return $s;
            }
        }

        return '';
    }

    /**
     * @param mixed $v
     */
    private static function isTruthy($v): bool
    {
        if ($v === true || $v === 1) {
            return true;
        }
        if (is_string($v)) {
            $s = strtoupper(trim($v));

            return $s === '1' || $s === 'Y' || $s === 'TRUE' || $s === 'YES';
        }

        return false;
    }

    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    private static function fetchList(array $filter, int $limit): array
    {
        $select = self::selectFields();
        $res = StoreTable::getList([
            'filter' => $filter,
            'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
            'limit' => $limit,
            'select' => $select,
        ]);

        $out = [];
        while ($row = $res->fetch()) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>|null
     */
    private static function fetchOne(array $filter): ?array
    {
        $rows = self::fetchList($filter, 1);
        if (count($rows) === 0) {
            return null;
        }

        return $rows[0];
    }

    /**
     * @return list<string>
     */
    private static function selectFields(): array
    {
        return [
            'ID',
            'TITLE',
            'ACTIVE',
            'ADDRESS',
            'DESCRIPTION',
            'XML_ID',
            'CODE',
            'SORT',
            'SITE_ID',
            'PHONE',
            'EMAIL',
            'SCHEDULE',
            'ISSUING_CENTER',
            'SHIPPING_CENTER',
            'GPS_N',
            'GPS_S',
            'LOCATION_ID',
            'IMAGE_ID',
            'DATE_CREATE',
            'DATE_MODIFY',
        ];
    }

    /**
     * @return array{ok: true, total: int, stores: list<array<string, mixed>>}
     */
    private static function okPayload(array $stores, int $total, bool $includeInactive): array
    {
        return [
            'ok' => true,
            'api_version' => JsonResponse::API_VERSION,
            'include_inactive' => $includeInactive,
            'total' => $total,
            'stores' => $stores,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function rowToJson(array $row): array
    {
        $id = (int) ($row['ID'] ?? 0);

        return [
            'store_id' => $id,
            'title' => (string) ($row['TITLE'] ?? ''),
            'active' => ($row['ACTIVE'] ?? '') === 'Y',
            'address' => (string) ($row['ADDRESS'] ?? ''),
            'description' => (string) ($row['DESCRIPTION'] ?? ''),
            'store_xml_id' => (string) ($row['XML_ID'] ?? ''),
            'store_code' => (string) ($row['CODE'] ?? ''),
            'sort' => (int) ($row['SORT'] ?? 0),
            'site_id' => (string) ($row['SITE_ID'] ?? ''),
            'phone' => (string) ($row['PHONE'] ?? ''),
            'email' => (string) ($row['EMAIL'] ?? ''),
            'schedule' => (string) ($row['SCHEDULE'] ?? ''),
            'issuing_center' => ($row['ISSUING_CENTER'] ?? '') === 'Y',
            'shipping_center' => ($row['SHIPPING_CENTER'] ?? '') === 'Y',
            'gps_n' => (string) ($row['GPS_N'] ?? ''),
            'gps_s' => (string) ($row['GPS_S'] ?? ''),
            'location_id' => (int) ($row['LOCATION_ID'] ?? 0),
            'image_id' => (string) ($row['IMAGE_ID'] ?? ''),
        ];
    }
}