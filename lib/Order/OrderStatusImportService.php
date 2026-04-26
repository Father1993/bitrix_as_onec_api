<?php

namespace As\OnecApi\Order;

use As\OnecApi\Http\JsonResponse;
use As\OnecApi\Stock\StockEngineBootstrap;
use As\OnecApi\StockImportOptions;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Order;

final class OrderStatusImportService
{
    /**
     * @return array{http_code:int, data:array<string, mixed>}
     */
    public static function run(): array
    {
        if (!Loader::includeModule('sale') || !Loader::includeModule('catalog') || !Loader::includeModule('iblock')) {
            return [
                'http_code' => 500,
                'data' => [
                    'ok' => false,
                    'error' => 'MODULES',
                    'message' => 'Не подключены модули sale, catalog или iblock.',
                ],
            ];
        }

        StockEngineBootstrap::ensureLoaded();

        $maxBodyBytes = StockImportOptions::getMaxBodyBytes();
        $maxItems = StockImportOptions::getMaxItems();

        $raw = file_get_contents('php://input');
        if ($raw === false) {
            return [
                'http_code' => 400,
                'data' => [
                    'ok' => false,
                    'error' => 'EMPTY_BODY',
                    'message' => 'Пустое тело запроса.',
                ],
            ];
        }

        if (strlen($raw) > $maxBodyBytes) {
            return JsonResponse::payloadTooLarge($maxBodyBytes);
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'http_code' => 400,
                'data' => [
                    'ok' => false,
                    'error' => 'INVALID_JSON',
                    'message' => 'Некорректный JSON.',
                ],
            ];
        }

        $auth = asStockImportFrom1cAuth($decoded);
        if (!$auth['ok']) {
            return [
                'http_code' => 401,
                'data' => [
                    'ok' => false,
                    'error' => 'AUTH',
                    'message' => $auth['message'] ?? 'Ошибка авторизации.',
                ],
            ];
        }

        $items = asStockImportFrom1cExtractItems($decoded);
        if ($items === null) {
            return [
                'http_code' => 400,
                'data' => [
                    'ok' => false,
                    'error' => 'INVALID_ITEMS',
                    'message' => 'Ожидается непустой массив items или корневой JSON-массив событий статусов заказа.',
                ],
            ];
        }

        if (count($items) > $maxItems) {
            return [
                'http_code' => 400,
                'data' => [
                    'ok' => false,
                    'error' => 'TOO_MANY_ITEMS',
                    'message' => 'Слишком много позиций в запросе.',
                    'max_items' => $maxItems,
                ],
            ];
        }

        $summary = [
            'ok' => true,
            'total' => count($items),
            'updated' => 0,
            'failed' => 0,
            'no_change' => 0,
            'results' => [],
            'errors' => [],
        ];

        foreach ($items as $index => $row) {
            $normalized = self::validateItem($row);
            if (!$normalized['ok']) {
                self::pushError($summary, $index, is_array($row) ? $row : [], $normalized['message']);
                continue;
            }

            $payload = $normalized['row'];
            $mapped = OrderStatusMapper::map($payload);
            if (!$mapped['ok']) {
                self::pushError($summary, $index, $payload, $mapped['message']);
                continue;
            }

            $resolved = OrderResolver::resolve($payload);
            if (!$resolved['ok']) {
                self::pushError($summary, $index, $payload, $resolved['message']);
                continue;
            }

            $validation = StatusTransitionValidator::validate($resolved, $mapped);
            if (!$validation['ok']) {
                self::pushError($summary, $index, $payload, $validation['message']);
                continue;
            }

            $order = Order::load($resolved['order_id']);
            if (!$order) {
                self::pushError($summary, $index, $payload, 'Не удалось загрузить заказ через D7 API.');
                continue;
            }

            $before = self::snapshot($order);
            if (self::isNoChange($before, $mapped)) {
                $summary['no_change']++;
                self::pushResult($summary, [
                    'index' => $index,
                    'state' => 'no_change',
                    'order_id' => $resolved['order_id'],
                    'order_xml_id' => $resolved['order_xml_id'],
                    'status_id' => $before['status_id'],
                    'status_name' => StatusTransitionValidator::getStatusInfo($before['status_id'])['name'],
                ]);
                asStockImportFrom1cLog('order_status_no_change', [
                    'order_id' => $resolved['order_id'],
                    'order_xml_id' => $resolved['order_xml_id'],
                    'status' => $before['status_id'],
                    'source_code' => $mapped['source_code'],
                    'event_at' => $mapped['event_at'],
                    'comment' => $mapped['comment'],
                ]);
                continue;
            }

            $apply = self::applyAction($order, $mapped);
            if (!$apply['ok']) {
                self::pushError($summary, $index, $payload, $apply['message']);
                continue;
            }

            try {
                $GLOBALS['AS_ONEC_API_SKIP_ORDER_MUTATORS'] = true;
                $saveResult = $order->save();
            } finally {
                unset($GLOBALS['AS_ONEC_API_SKIP_ORDER_MUTATORS']);
            }

            if (!$saveResult->isSuccess()) {
                self::pushError(
                    $summary,
                    $index,
                    $payload,
                    'Не удалось сохранить заказ: ' . implode('; ', $saveResult->getErrorMessages())
                );
                continue;
            }

            $after = self::snapshot($order);
            $summary['updated']++;
            self::pushResult($summary, [
                'index' => $index,
                'state' => 'updated',
                'order_id' => $resolved['order_id'],
                'order_xml_id' => $resolved['order_xml_id'],
                'status_id' => $after['status_id'],
                'status_name' => StatusTransitionValidator::getStatusInfo($after['status_id'])['name'],
            ]);
            asStockImportFrom1cLog('order_status_updated', [
                'order_id' => $resolved['order_id'],
                'order_xml_id' => $resolved['order_xml_id'],
                'source_code' => $mapped['source_code'],
                'event_at' => $mapped['event_at'],
                'comment' => $mapped['comment'],
                'before_status' => $before['status_id'],
                'after_status' => $after['status_id'],
                'before_paid' => $before['paid'],
                'after_paid' => $after['paid'],
                'before_allow_delivery' => $before['allow_delivery'],
                'after_allow_delivery' => $after['allow_delivery'],
                'before_deducted' => $before['deducted'],
                'after_deducted' => $after['deducted'],
            ]);
        }

        if ($summary['failed'] > count($summary['errors'])) {
            $summary['errors_truncated'] = true;
        }

        asStockImportFrom1cLog('order_status_import_done', [
            'updated' => $summary['updated'],
            'failed' => $summary['failed'],
            'no_change' => $summary['no_change'],
        ]);

        return [
            'http_code' => 200,
            'data' => $summary,
        ];
    }

    /**
     * @param mixed $row
     * @return array{ok:true,row:array<string, mixed>}|array{ok:false,message:string}
     */
    private static function validateItem($row): array
    {
        if (!is_array($row)) {
            return ['ok' => false, 'message' => 'Позиция должна быть объектом JSON.'];
        }

        $orderXmlId = trim((string) ($row['order_xml_id'] ?? $row['xml_id'] ?? ''));
        $orderIdRaw = $row['order_id'] ?? $row['id'] ?? null;
        $orderId = 0;
        if ($orderIdRaw !== null && $orderIdRaw !== '') {
            if (!is_numeric($orderIdRaw)) {
                return ['ok' => false, 'message' => 'Поле order_id должно быть числом.'];
            }
            $orderId = (int) $orderIdRaw;
            if ($orderId <= 0) {
                return ['ok' => false, 'message' => 'Поле order_id должно быть положительным числом.'];
            }
        }

        foreach (['paid', 'allow_delivery', 'deducted'] as $boolKey) {
            if (array_key_exists($boolKey, $row) && !self::isBoolLike($row[$boolKey])) {
                return ['ok' => false, 'message' => 'Поле ' . $boolKey . ' должно быть boolean-подобным значением (Y/N, true/false, 1/0).'];
            }
        }

        $statusCode = trim((string) (
            $row['status_code_1c']
            ?? $row['status_code']
            ?? $row['status']
            ?? $row['event_code']
            ?? ''
        ));

        if ($orderXmlId === '' && $orderId <= 0) {
            return ['ok' => false, 'message' => 'Нужен order_xml_id или order_id.'];
        }

        if ($statusCode === '' && !array_key_exists('paid', $row) && !array_key_exists('allow_delivery', $row) && !array_key_exists('deducted', $row)) {
            return ['ok' => false, 'message' => 'Нужен status_code_1c/status_code/status/event_code или поля paid/allow_delivery/deducted.'];
        }

        $eventAt = trim((string) ($row['event_at'] ?? $row['event_date'] ?? $row['date'] ?? ''));
        if ($eventAt !== '' && self::parseDateTime($eventAt) === null) {
            return ['ok' => false, 'message' => 'Поле event_at/event_date/date должно быть корректной датой/временем.'];
        }

        return [
            'ok' => true,
            'row' => [
                'order_xml_id' => $orderXmlId,
                'order_id' => $orderId,
                'status_code_1c' => $statusCode,
                'paid' => $row['paid'] ?? null,
                'allow_delivery' => $row['allow_delivery'] ?? null,
                'deducted' => $row['deducted'] ?? null,
                'comment' => trim((string) ($row['comment'] ?? '')),
                'event_at' => $eventAt,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $row
     */
    private static function pushError(array &$summary, int $index, array $row, string $message): void
    {
        $summary['failed']++;
        if (count($summary['errors']) < 200) {
            $summary['errors'][] = [
                'index' => $index,
                'order_xml_id' => $row['order_xml_id'] ?? $row['xml_id'] ?? null,
                'order_id' => $row['order_id'] ?? $row['id'] ?? null,
                'message' => $message,
            ];
        }
        asStockImportFrom1cLog('order_status_error', [
            'index' => $index,
            'order_xml_id' => $row['order_xml_id'] ?? $row['xml_id'] ?? '',
            'order_id' => $row['order_id'] ?? $row['id'] ?? 0,
            'message' => $message,
        ]);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $result
     */
    private static function pushResult(array &$summary, array $result): void
    {
        if (count($summary['results']) >= 200) {
            return;
        }

        $summary['results'][] = $result;
    }

    /**
     * @param array{target_status:string,paid:?bool,allow_delivery:?bool,deducted:?bool,event_at:string} $action
     * @return array{ok:true}|array{ok:false,message:string}
     */
    private static function applyAction(Order $order, array $action): array
    {
        $eventDate = self::parseDateTime((string) ($action['event_at'] ?? ''));

        $targetStatus = (string) ($action['target_status'] ?? '');
        if ($targetStatus !== '') {
            $order->setField('STATUS_ID', $targetStatus);
        }

        if (($action['paid'] ?? null) !== null) {
            $payments = self::getRealPayments($order);
            if ($payments === []) {
                return ['ok' => false, 'message' => 'У заказа нет платежей для синхронизации статуса оплаты.'];
            }

            foreach ($payments as $payment) {
                $payment->setField('PAID', $action['paid'] ? 'Y' : 'N');
                if ($action['paid'] && $eventDate !== null) {
                    $payment->setField('DATE_PAID', $eventDate);
                }
            }
        }

        if (($action['allow_delivery'] ?? null) !== null || ($action['deducted'] ?? null) !== null) {
            $shipments = self::getRealShipments($order);
            if ($shipments === []) {
                return ['ok' => false, 'message' => 'У заказа нет отгрузок для синхронизации.'];
            }

            foreach ($shipments as $shipment) {
                if (($action['allow_delivery'] ?? null) !== null) {
                    $shipment->setField('ALLOW_DELIVERY', $action['allow_delivery'] ? 'Y' : 'N');
                }
                if (($action['deducted'] ?? null) !== null) {
                    $shipment->setField('DEDUCTED', $action['deducted'] ? 'Y' : 'N');
                    if ($action['deducted'] && $eventDate !== null) {
                        $shipment->setField('DATE_DEDUCTED', $eventDate);
                    }
                }
            }
        }

        return ['ok' => true];
    }

    /**
     * @return array{status_id:string,paid:string,allow_delivery:string,deducted:string}
     */
    private static function snapshot(Order $order): array
    {
        $payments = self::getRealPayments($order);
        $shipments = self::getRealShipments($order);

        return [
            'status_id' => (string) $order->getField('STATUS_ID'),
            'paid' => self::allEntitiesFlag($payments, 'PAID'),
            'allow_delivery' => self::allEntitiesFlag($shipments, 'ALLOW_DELIVERY'),
            'deducted' => self::allEntitiesFlag($shipments, 'DEDUCTED'),
        ];
    }

    /**
     * @param array{status_id:string,paid:string,allow_delivery:string,deducted:string} $snapshot
     * @param array{target_status:string,paid:?bool,allow_delivery:?bool,deducted:?bool} $action
     */
    private static function isNoChange(array $snapshot, array $action): bool
    {
        if (($action['target_status'] ?? '') !== '' && $snapshot['status_id'] !== $action['target_status']) {
            return false;
        }
        if (($action['paid'] ?? null) !== null && $snapshot['paid'] !== ($action['paid'] ? 'Y' : 'N')) {
            return false;
        }
        if (($action['allow_delivery'] ?? null) !== null && $snapshot['allow_delivery'] !== ($action['allow_delivery'] ? 'Y' : 'N')) {
            return false;
        }
        if (($action['deducted'] ?? null) !== null && $snapshot['deducted'] !== ($action['deducted'] ? 'Y' : 'N')) {
            return false;
        }

        return true;
    }

    /**
     * @return list<object>
     */
    private static function getRealPayments(Order $order): array
    {
        $payments = [];
        foreach ($order->getPaymentCollection() as $payment) {
            if (method_exists($payment, 'isInner') && $payment->isInner()) {
                continue;
            }
            $payments[] = $payment;
        }

        return $payments;
    }

    /**
     * @return list<object>
     */
    private static function getRealShipments(Order $order): array
    {
        $shipments = [];
        foreach ($order->getShipmentCollection() as $shipment) {
            if (method_exists($shipment, 'isSystem') && $shipment->isSystem()) {
                continue;
            }
            $shipments[] = $shipment;
        }

        return $shipments;
    }

    /**
     * @param list<object> $entities
     */
    private static function allEntitiesFlag(array $entities, string $field): string
    {
        if ($entities === []) {
            return 'N';
        }

        foreach ($entities as $entity) {
            if ((string) $entity->getField($field) !== 'Y') {
                return 'N';
            }
        }

        return 'Y';
    }

    /**
     * @param mixed $value
     */
    private static function isBoolLike($value): bool
    {
        if (is_bool($value)) {
            return true;
        }
        if (is_int($value) || is_float($value)) {
            return in_array((int) $value, [0, 1], true);
        }
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtoupper(trim($value)), ['Y', 'N', 'YES', 'NO', 'TRUE', 'FALSE', '1', '0'], true);
    }

    private static function parseDateTime(string $value): ?DateTime
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
