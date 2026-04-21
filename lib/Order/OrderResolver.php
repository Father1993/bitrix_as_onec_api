<?php

namespace As\OnecApi\Order;

use Bitrix\Sale\Internals\OrderTable;

final class OrderResolver
{
    /**
     * @param array<string, mixed> $row
     * @return array{ok:true,order_id:int,order_xml_id:string,current_status:string,payed:string,canceled:string}|array{ok:false,message:string}
     */
    public static function resolve(array $row): array
    {
        $orderXmlId = trim((string) ($row['order_xml_id'] ?? ''));
        $orderId = (int) ($row['order_id'] ?? 0);

        if ($orderXmlId !== '') {
            $resolved = self::resolveByXmlId($orderXmlId);
            if (!$resolved['ok']) {
                return $resolved;
            }

            if ($orderId > 0 && $orderId !== $resolved['order_id']) {
                return ['ok' => false, 'message' => 'order_id не соответствует order_xml_id.'];
            }

            return $resolved;
        }

        if ($orderId > 0) {
            return self::resolveById($orderId);
        }

        return ['ok' => false, 'message' => 'Нужен order_xml_id или order_id.'];
    }

    /**
     * @return array{ok:true,order_id:int,order_xml_id:string,current_status:string,payed:string,canceled:string}|array{ok:false,message:string}
     */
    private static function resolveByXmlId(string $xmlId): array
    {
        $rows = OrderTable::getList([
            'filter' => ['=XML_ID' => $xmlId],
            'select' => ['ID', 'XML_ID', 'STATUS_ID', 'PAYED', 'CANCELED'],
            'order' => ['ID' => 'ASC'],
            'limit' => 2,
        ])->fetchAll();

        if ($rows === []) {
            return ['ok' => false, 'message' => 'Заказ с указанным XML_ID не найден.'];
        }

        if (count($rows) > 1) {
            return ['ok' => false, 'message' => 'Найдено несколько заказов с одинаковым XML_ID.'];
        }

        return self::normalizeRow($rows[0]);
    }

    /**
     * @return array{ok:true,order_id:int,order_xml_id:string,current_status:string,payed:string,canceled:string}|array{ok:false,message:string}
     */
    private static function resolveById(int $orderId): array
    {
        $row = OrderTable::getList([
            'filter' => ['=ID' => $orderId],
            'select' => ['ID', 'XML_ID', 'STATUS_ID', 'PAYED', 'CANCELED'],
            'limit' => 1,
        ])->fetch();

        if (!$row) {
            return ['ok' => false, 'message' => 'Заказ с указанным ID не найден.'];
        }

        return self::normalizeRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ok:true,order_id:int,order_xml_id:string,current_status:string,payed:string,canceled:string}
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'ok' => true,
            'order_id' => (int) $row['ID'],
            'order_xml_id' => (string) ($row['XML_ID'] ?? ''),
            'current_status' => (string) ($row['STATUS_ID'] ?? ''),
            'payed' => (string) ($row['PAYED'] ?? 'N'),
            'canceled' => (string) ($row['CANCELED'] ?? 'N'),
        ];
    }
}
