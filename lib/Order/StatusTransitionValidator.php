<?php

namespace As\OnecApi\Order;

use Bitrix\Sale\Internals\StatusLangTable;
use Bitrix\Sale\Internals\StatusTable;

final class StatusTransitionValidator
{
    /**
     * @param array{current_status:string,canceled:string,payed:string} $resolvedOrder
     * @param array{target_status:string,paid:?bool,allow_delivery:?bool,deducted:?bool} $action
     * @return array{ok:true}|array{ok:false,message:string}
     */
    public static function validate(array $resolvedOrder, array $action): array
    {
        $targetStatus = (string) ($action['target_status'] ?? '');
        if ($targetStatus !== '' && !self::statusExists($targetStatus)) {
            return ['ok' => false, 'message' => 'Целевой статус заказа не существует в Bitrix.'];
        }

        if (($resolvedOrder['canceled'] ?? 'N') === 'Y' && $targetStatus !== '' && $targetStatus !== $resolvedOrder['current_status']) {
            return ['ok' => false, 'message' => 'Нельзя менять статус отменённого заказа без отдельной политики.'];
        }

        if ($targetStatus !== '') {
            $allowed = OrderStatusOptions::getAllowedTransitions();
            $currentStatus = (string) ($resolvedOrder['current_status'] ?? '');
            if ($currentStatus !== '' && isset($allowed[$currentStatus]) && is_array($allowed[$currentStatus])) {
                $allowedTargets = array_values(array_filter(array_map('strval', $allowed[$currentStatus])));
                if ($targetStatus !== $currentStatus && !in_array($targetStatus, $allowedTargets, true)) {
                    return ['ok' => false, 'message' => 'Переход статуса не разрешён политикой модуля.'];
                }
            }
        }

        if (($action['paid'] ?? null) !== null && !OrderStatusOptions::allowPaymentSync()) {
            return ['ok' => false, 'message' => 'Синхронизация оплат отключена в настройках модуля.'];
        }

        if ((($action['allow_delivery'] ?? null) !== null || ($action['deducted'] ?? null) !== null) && !OrderStatusOptions::allowShipmentSync()) {
            return ['ok' => false, 'message' => 'Синхронизация отгрузок отключена в настройках модуля.'];
        }

        return ['ok' => true];
    }

    /**
     * @return array{id:string,name:string}
     */
    public static function getStatusInfo(string $statusId): array
    {
        $statusId = strtoupper(trim($statusId));
        if ($statusId === '') {
            return ['id' => '', 'name' => ''];
        }

        $row = StatusTable::getList([
            'filter' => ['=ID' => $statusId, '=TYPE' => 'O'],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();

        if (!$row) {
            return ['id' => '', 'name' => ''];
        }

        $nameRow = StatusLangTable::getList([
            'filter' => ['=STATUS_ID' => $statusId, '=LID' => LANGUAGE_ID],
            'select' => ['NAME'],
            'limit' => 1,
        ])->fetch();

        return [
            'id' => $statusId,
            'name' => (string) ($nameRow['NAME'] ?? ''),
        ];
    }

    private static function statusExists(string $statusId): bool
    {
        return self::getStatusInfo($statusId)['id'] !== '';
    }
}
