<?php
require_once 'base.php';

$payload = [
    'filter' => [[
        'type' => 'gte',
        'field' => 'updated',
        'value' => file_get_contents('last_updated.txt')
    ]],
    'order-by' => [[
        'type' => 'field',
        'field' => 'updated',
        'direction' => 'asc'
    ]],
    'per_page' => 250
];
$new_orders = send_request($oa_base . 'products/order', $payload)['_embedded']['order'];
log_to_file(date('d.m.Y H:i:s') . '. Найдено заказов для обновления: ' . sizeof($new_orders));

if (!$new_orders)
    die();

log_to_file(implode(', ', array_map(function ($entry) { return $entry['id']; }, $new_orders)));

foreach ($new_orders as $order) {
    $query = [
        'by' => 'id'
    ];
    $retail_order = send_request($retail_base . 'orders/' . $order['extId'], $query);
    if (!$retail_order['success'])
        continue;
    $retail_order = $retail_order['order'];

    $retail_status = null;
    if (array_key_exists($order['state'], $statuses)) {
        $retail_status = $statuses[$order['state']];
    }
    $payload = [
        'by' => 'id',
        'site' => $retail_order['site'],
        'order' => []
    ];
    if ($retail_status != null and $retail_status != $retail_order['status']) {
        $payload['order']['status'] = $retail_status;
    }

    if (array_key_exists('deliveryRequest', $order['_embedded']) && array_key_exists('id', $order['_embedded']['deliveryRequest'])) {
        $delivery_request = send_request($oa_base . 'delivery-services/requests/' . $order['_embedded']['deliveryRequest']['id']);
        $current_tracking_number = null;
        if (array_key_exists($tracking_number_field, $retail_order['customFields'])) {
            $current_tracking_number = $retail_order['customFields'][$tracking_number_field];
        }
        if ($delivery_request['trackingNumber'] and $delivery_request['trackingNumber'] != $current_tracking_number) {
            $payload['order']['customFields'] = [
                $tracking_number_field => $delivery_request['trackingNumber']
            ];
        }
    }

    if (!empty($payload['order'])) {
        log_to_file('Обновление заказа '. $retail_order['id'] . ':');
        log_to_file($payload);

        $payload['order'] = json_encode($payload['order']);
        $payload = http_build_query($payload);
        send_request($retail_base . 'orders/' . $retail_order['id'] . '/edit', null,  $payload, null, 'POST');
    }

    file_put_contents('last_updated.txt', $order['updated'], LOCK_EX);
}