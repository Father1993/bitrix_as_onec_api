<?php

namespace As\OnecApi\Order;

use As\OnecApi\Http\JsonResponse;
use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\OrderTable;
use Bitrix\Sale\Order;

final class OrderReadService
{
    /**
     * @param array<string, mixed> $query
     * @return array{http_code:int,data:array<string,mixed>}
     */
    public static function query(array $query): array
    {
        if (!Loader::includeModule('sale')) {
            return [
                'http_code' => 500,
                'data' => [
                    'ok' => false,
                    'error' => 'MODULES',
                    'message' => 'Не подключен модуль sale.',
                    'api_version' => JsonResponse::API_VERSION,
                ],
            ];
        }

        $singleOrderXmlId = trim((string) ($query['order_xml_id'] ?? $query['xml_id'] ?? ''));
        $singleOrderId = isset($query['order_id']) ? (int) $query['order_id'] : 0;

        if ($singleOrderXmlId !== '' || $singleOrderId > 0) {
            $resolved = OrderResolver::resolve([
                'order_xml_id' => $singleOrderXmlId,
                'order_id' => $singleOrderId,
            ]);
            if (!$resolved['ok']) {
                return [
                    'http_code' => 404,
                    'data' => [
                        'ok' => false,
                        'error' => 'ORDER_NOT_FOUND',
                        'message' => $resolved['message'],
                        'api_version' => JsonResponse::API_VERSION,
                    ],
                ];
            }

            $order = Order::load($resolved['order_id']);
            if (!$order) {
                return [
                    'http_code' => 404,
                    'data' => [
                        'ok' => false,
                        'error' => 'ORDER_NOT_FOUND',
                        'message' => 'Не удалось загрузить заказ.',
                        'api_version' => JsonResponse::API_VERSION,
                    ],
                ];
            }

            return [
                'http_code' => 200,
                'data' => [
                    'ok' => true,
                    'api_version' => JsonResponse::API_VERSION,
                    'order' => self::buildOrderPayload($order),
                ],
            ];
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $itemsPerPage = max(1, min((int) ($query['items_per_page'] ?? 20), 100));
        $offset = ($page - 1) * $itemsPerPage;

        $filter = [];
        $statusId = strtoupper(trim((string) ($query['status_id'] ?? '')));
        if ($statusId !== '') {
            $filter['=STATUS_ID'] = $statusId;
        }

        $orderXmlIdFilter = trim((string) ($query['order_xml_id_like'] ?? ''));
        if ($orderXmlIdFilter !== '') {
            $filter['%XML_ID'] = $orderXmlIdFilter;
        }

        $result = OrderTable::getList([
            'filter' => $filter,
            'select' => ['ID'],
            'order' => ['ID' => 'DESC'],
            'limit' => $itemsPerPage,
            'offset' => $offset,
            'count_total' => true,
        ]);

        $orderIds = [];
        while ($row = $result->fetch()) {
            $orderIds[] = (int) $row['ID'];
        }

        $orders = [];
        foreach ($orderIds as $orderId) {
            $order = Order::load($orderId);
            if ($order) {
                $orders[] = self::buildOrderPayload($order);
            }
        }

        $total = (int) $result->getCount();
        $totalPages = $total > 0 ? (int) ceil($total / $itemsPerPage) : 0;

        return [
            'http_code' => 200,
            'data' => [
                'ok' => true,
                'api_version' => JsonResponse::API_VERSION,
                'orders' => $orders,
                'params' => [
                    'page' => $page,
                    'items_per_page' => $itemsPerPage,
                    'status_id' => $statusId,
                    'order_xml_id_like' => $orderXmlIdFilter,
                    'total_items' => $total,
                    'total_pages' => $totalPages,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildOrderPayload(Order $order): array
    {
        $statusInfo = StatusTransitionValidator::getStatusInfo((string) $order->getField('STATUS_ID'));
        $properties = self::extractProperties($order);
        $items = self::extractItems($order);
        $payments = self::extractPayments($order);
        $shipments = self::extractShipments($order);
        $syncFlags = self::extractSyncFlags($payments, $shipments);
        $pickup = self::extractPickupInfo($order, $properties);

        return [
            'id' => (int) $order->getId(),
            'account_number' => (string) $order->getField('ACCOUNT_NUMBER'),
            'xml_id' => (string) $order->getField('XML_ID'),
            'date_insert' => (string) $order->getField('DATE_INSERT'),
            'date_status' => (string) $order->getField('DATE_STATUS'),
            'price' => (float) $order->getPrice(),
            'currency' => (string) $order->getCurrency(),
            'status_id' => (string) $order->getField('STATUS_ID'),
            'status_name' => $statusInfo['name'],
            'payed' => (string) $order->getField('PAYED'),
            'canceled' => (string) $order->getField('CANCELED'),
            'person_type_id' => (int) $order->getPersonTypeId(),
            'delivery_id' => (string) $order->getField('DELIVERY_ID'),
            'user_description' => (string) $order->getField('USER_DESCRIPTION'),
            'allow_delivery' => $syncFlags['ALLOW_DELIVERY'],
            'deducted' => $syncFlags['DEDUCTED'],
            'order_description' => self::buildOrderDescription($order, $properties, $pickup),
            'pickup' => $pickup,
            'properties' => $properties,
            'items' => $items,
            'payments' => $payments,
            'shipments' => $shipments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractProperties(Order $order): array
    {
        $result = [];
        foreach ($order->getPropertyCollection() as $propertyItem) {
            $code = (string) $propertyItem->getField('CODE');
            if ($code === '') {
                continue;
            }
            $result[$code] = $propertyItem->getValue();
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function extractItems(Order $order): array
    {
        $items = [];
        foreach ($order->getBasket() as $basketItem) {
            if (method_exists($basketItem, 'isBundleChild') && $basketItem->isBundleChild()) {
                continue;
            }

            $quantity = (float) $basketItem->getQuantity();
            $price = (float) $basketItem->getPrice();
            $items[] = [
                'basket_id' => (int) $basketItem->getId(),
                'product_id' => (int) $basketItem->getProductId(),
                'product_xml_id' => (string) $basketItem->getField('PRODUCT_XML_ID'),
                'name' => (string) $basketItem->getField('NAME'),
                'price' => $price,
                'quantity' => $quantity,
                'summ' => $price * $quantity,
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function extractPayments(Order $order): array
    {
        $payments = [];
        foreach ($order->getPaymentCollection() as $payment) {
            if (method_exists($payment, 'isInner') && $payment->isInner()) {
                continue;
            }

            $payments[] = [
                'id' => (int) $payment->getId(),
                'sum' => (float) $payment->getSum(),
                'currency' => (string) $payment->getField('CURRENCY'),
                'paid' => (string) $payment->getField('PAID'),
                'date_paid' => (string) $payment->getField('DATE_PAID'),
                'pay_system_id' => (int) $payment->getPaymentSystemId(),
                'pay_system_name' => (string) $payment->getField('PAY_SYSTEM_NAME'),
            ];
        }

        return $payments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function extractShipments(Order $order): array
    {
        $shipments = [];
        foreach ($order->getShipmentCollection() as $shipment) {
            if (method_exists($shipment, 'isSystem') && $shipment->isSystem()) {
                continue;
            }

            $shipments[] = [
                'id' => (int) $shipment->getId(),
                'delivery_id' => (int) $shipment->getField('DELIVERY_ID'),
                'delivery_name' => (string) $shipment->getField('DELIVERY_NAME'),
                'allow_delivery' => (string) $shipment->getField('ALLOW_DELIVERY'),
                'deducted' => (string) $shipment->getField('DEDUCTED'),
                'date_deducted' => (string) $shipment->getField('DATE_DEDUCTED'),
                'tracking_number' => (string) $shipment->getField('TRACKING_NUMBER'),
            ];
        }

        return $shipments;
    }

    /**
     * @param list<array<string, mixed>> $payments
     * @param list<array<string, mixed>> $shipments
     * @return array{ALLOW_DELIVERY:string,DEDUCTED:string}
     */
    private static function extractSyncFlags(array $payments, array $shipments): array
    {
        $allowDelivery = 'N';
        $deducted = 'N';

        if ($shipments !== []) {
            $allowDelivery = 'Y';
            $deducted = 'Y';
            foreach ($shipments as $shipment) {
                if (($shipment['allow_delivery'] ?? 'N') !== 'Y') {
                    $allowDelivery = 'N';
                }
                if (($shipment['deducted'] ?? 'N') !== 'Y') {
                    $deducted = 'N';
                }
            }
        }

        return [
            'ALLOW_DELIVERY' => $allowDelivery,
            'DEDUCTED' => $deducted,
        ];
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, string>
     */
    private static function extractPickupInfo(Order $order, array $properties): array
    {
        $result = [
            'pickup_store' => '',
            'pickup_store_id' => '',
            'pickup_store_name' => '',
            'pickup_store_address' => '',
            'pickup_store_schedule' => '',
            'pickup_store_phone' => '',
            'pickup_address' => '',
        ];

        $deliveryId = (string) $order->getField('DELIVERY_ID');
        if (\defined('DELIVERY_TYPE_PICKUP') && $deliveryId !== (string) \constant('DELIVERY_TYPE_PICKUP')) {
            return $result;
        }

        $city = (string) ($properties['CITY'] ?? '');
        $result['pickup_address'] = self::resolveLegacyPickupAddress($city);

        $storeId = trim((string) ($properties['PICKUP_STORE'] ?? ''));
        if ($storeId === '') {
            return $result;
        }

        $info = self::getPickupStoreInfo($storeId);

        return [
            'pickup_store' => $storeId,
            'pickup_store_id' => $storeId,
            'pickup_store_name' => (string) ($info['name'] ?? ''),
            'pickup_store_address' => (string) ($info['address'] ?? ''),
            'pickup_store_schedule' => (string) ($info['schedule'] ?? ''),
            'pickup_store_phone' => (string) ($info['phone'] ?? ''),
            'pickup_address' => $result['pickup_address'],
        ];
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, string> $pickup
     */
    private static function buildOrderDescription(Order $order, array $properties, array $pickup): string
    {
        $deliveryText = '';
        $deliveryId = (string) $order->getField('DELIVERY_ID');
        if (\defined('DELIVERY_TYPE_DELIVERY') && $deliveryId === (string) \constant('DELIVERY_TYPE_DELIVERY')) {
            $deliveryText = 'Доставка';
        } elseif (\defined('DELIVERY_TYPE_PICKUP') && $deliveryId === (string) \constant('DELIVERY_TYPE_PICKUP')) {
            $deliveryText = 'Самовывоз';
        }

        $address = trim((string) ($pickup['pickup_address'] ?? '') . (string) ($properties['ADDRESS'] ?? ''));

        return 'Номер заказа: ' . $order->getId() . "\n"
            . 'Способ доставки: ' . $deliveryText . "\n"
            . 'Адрес доставки: ' . $address . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function getPickupStoreInfo(string $storeId): array
    {
        $stores = $GLOBALS['PICKUP_STORES'] ?? [];
        if (!is_array($stores)) {
            return [];
        }

        foreach ($stores as $cityStores) {
            if (!is_array($cityStores)) {
                continue;
            }
            foreach ($cityStores as $store) {
                if (is_array($store) && (string) ($store['id'] ?? '') === $storeId) {
                    return $store;
                }
            }
        }

        return [];
    }

    private static function resolveLegacyPickupAddress(string $city): string
    {
        switch ($city) {
            case 'Хабаровск':
                return '680006, г. Хабаровск, ул. Иртышская, 25';
            case 'Владивосток':
                return '690039, г. Владивосток, ул.Енисейская, 32, складской комплекс 6';
            case 'Южно-Сахалинск':
                return '693012, г. Южно-Сахалинск, проспект Мира 2Б/8';
            case 'Благовещенск':
                return (string) ($GLOBALS['PICKUP_ADDRESSES']['blg'] ?? '');
            default:
                return '';
        }
    }
}
