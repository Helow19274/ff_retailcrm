<?php
if (!array_key_exists('code', $_POST)
    or $_POST['code'] != 'e804b0dadc8981c107cc70502891327a'
    or !array_key_exists('orderId', $_POST)
    or !array_key_exists('action', $_POST)
    or !in_array($_POST['action'], ['create', 'cancel'])
) {
    http_response_code(403);
    die();
}

require_once 'base.php';

log_to_file('----' . $_POST['orderId'] . ' ' . $_POST['action'] . ' ' . date('d.m.Y H:i:s') . '------');
log_to_file($_POST);

function failed_to_create_order($reason) {
    global $order, $status_failed;
    log_to_file($reason);
    set_order_status($order['id'], $order['site'], $status_failed, $reason);
}

if ($_POST['action'] == 'create') {
    $query = [
        'by' => 'id'
    ];
    $order = send_request($retail_base . 'orders/' . $_POST['orderId'], $query)['order'];

    $tariff = null;
    if (array_key_exists('tariff', $order['delivery']['data'])) {
        $tariff = $order['delivery']['data']['tariff'];
    } else {
        $tariff = $order['delivery']['data']['tariffType'];
    }

    $payload = [
        'filter' => [[
            'type' => 'eq',
            'field' => 'extId',
            'value' => $tariff
        ]]
    ];
    $rate = send_request($oa_base . 'delivery-services/rates', $payload)['_embedded']['rates'];
    if (sizeof($rate) == 0) {
        failed_to_create_order('В ФФ не найден тариф доставки ' . $tariff);
        die();
    }

    $paid = $order['prepaySum'] != 0;

    $name = [];
    if (array_key_exists('lastName', $order)) {
        $name[] = $order['lastName'];
    }
    $name[] = $order['firstName'];
    if (array_key_exists('patronymic', $order)) {
        $name[] = $order['patronymic'];
    }

    $order_payload = [
        'paymentState' => $paid ? 'paid' : 'not_paid',
        'extId' => $order['id'],
        'shop' => $oa_shop,
        'profile' => [
            'name' => implode(' ', $name)
        ],
        'address' => [
        ],
        'eav' => [
            'order-reserve-warehouse' => $oa_warehouses[$order['shipmentStore']][0]
        ],
        'deliveryRequest' => [
            'sender' => $oa_warehouses[$order['shipmentStore']][1],
            'rate' => $rate[0]['id'],
            'deliveryService' => 1,
            'retailPrice' => $paid ? '0' : $order['delivery']['cost'],
            'payment' => $paid ? '0' : $order['totalSumm']
        ],
        'orderProducts' => []
    ];

    if (array_key_exists('trackNumber', $order['delivery']['data'])) {
        $order_payload['deliveryRequest']['trackingNumber'] = $order['delivery']['data']['trackNumber'];
    }

    if (array_key_exists('phone', $order)) {
        $order_payload['phone'] = $order['phone'];
    }
    if (array_key_exists('email', $order)) {
        $order_payload['profile']['email'] = $order['email'];
    }

    if (array_key_exists('pickuppointId', $order['delivery']['data'])) {
        $payload = [
            'filter' => [[
                'type' => 'eq',
                'field' => 'extId',
                'value' => $order['delivery']['data']['pickuppointId']
            ]]
        ];
        $servicePoint = send_request($oa_base . 'delivery-services/service-points', $payload)['_embedded']['servicePoints'];
        if (sizeof($servicePoint) == 0) {
            failed_to_create_order('В базе ФФ не найден выбранный ПВЗ, обратитесь в службу поддержки');
            die();
        }
        $order_payload['deliveryRequest']['servicePoint'] = $servicePoint[0]['id'];
        $order_payload['address']['locality'] = $servicePoint[0]['_embedded']['locality']['id'];
        $order_payload['address']['postcode'] = $servicePoint[0]['_embedded']['locality']['postcode'];
    } else {
        $order_payload['address']['notFormal'] = $order['delivery']['address']['city'] . ', ' . $order['delivery']['address']['text'];
        if (array_key_exists('street', $order['delivery']['address'])) {
            $order_payload['address']['street'] = $order['delivery']['address']['street'];
        }
        if (array_key_exists('building', $order['delivery']['address'])) {
            $order_payload['address']['house'] = $order['delivery']['address']['building'];
        }
        if (array_key_exists('housing', $order['delivery']['address'])) {
            $order_payload['address']['house'] .= ' к. ' . $order['delivery']['address']['housing'];
        }
        if (array_key_exists('flat', $order['delivery']['address'])) {
            $order_payload['address']['apartment'] = $order['delivery']['address']['flat'];
        }
        if (array_key_exists('house', $order['delivery']['address'])) {
            $order_payload['address']['block'] = $order['delivery']['address']['house'];
        }
        if (array_key_exists('index', $order['delivery']['address'])) {
            $order_payload['address']['postcode'] = $order['delivery']['address']['index'];
        }

        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => $cdek_key1,
            'client_secret' => $cdek_key2
        ];
        $key = send_request($cdek_base . 'oauth/token', $payload, null, null, 'POST')['access_token'];

        $payload = [
            'code' => $order['delivery']['data']['extraData']['delivery_location_code']
        ];
        $headers = [
            'Authorization: Bearer ' . $key
        ];
        $postal_codes = send_request($cdek_base . 'location/cities', $payload, null, $headers)[0]['postal_codes'];

        $payload = [
            'filter' => [[
                'type' => 'in',
                'field' => 'extId',
                'values' => array_slice($postal_codes, 0, 100)
            ]]
        ];
        $postcodes = send_request($oa_base . 'delivery-services/postcodes', $payload)['_embedded']['postcodes'];
        if (sizeof($postcodes) > 0) {
            $order_payload['address']['locality'] = $postcodes[0]['_embedded']['locality']['id'];
            if (!array_key_exists('postcode', $order_payload['address'])) {
                $order_payload['address']['postcode'] = $postcodes[0]['extId'];
            }
        }

        if (!array_key_exists('locality', $order_payload['address'])) {
            failed_to_create_order('В базе ФФ не найдено выбранное местоположение');
            die();
        }
    }

    function add_product($sku, $quantity, $price) {
        global $oa_base, $oa_shop, $order_payload;
        $payload = [
            'filter' => [[
                'type' => 'eq',
                'field' => 'article',
                'value' => $sku
            ]]
        ];
        $offer = send_request($oa_base . 'products/offer', $payload)['_embedded']['product_offer'];

        if (sizeof($offer) == 0) {
            failed_to_create_order('В базе ФФ не найден товар ' . $sku);
            die();
        }

        $order_payload['orderProducts'][] = [
            'count' => $quantity,
            'shop' => $oa_shop,
            'productOffer' => $offer[0]['id'],
            'price' => $price,
            'payment' => $price,
        ];
    }

    foreach ($order['items'] as $item) {
        $product_price = $item['initialPrice'] - $item['discountTotal'];
        if (strpos($item['offer']['article'], 'набор ') === 0) {
            $products = explode(',', mb_substr($item['offer']['article'], 6));
            $sold = 0;
            foreach ($products as $sku) {
                $price = round($product_price / sizeof($products), 2);
                if ($product_price - $sold - $price < 0.1) {
                    $price = round($product_price - $sold, 2);
                }
                add_product($sku, $item['quantity'], $price);
                $sold += $price;
            }
        } else {
            add_product($item['offer']['article'], $item['quantity'], $product_price);
        }
    }

    log_to_file($order_payload);

    $ch = send_request($oa_base . 'products/order', null, $order_payload, null,  'POST', true);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (intdiv($code, 100) != 2) {
        $json = json_decode($r, true);
        $error = $r;
        if ($json) {
            $error = $json['detail'];
        }
        failed_to_create_order('Ошибка создания заказа: ' . $error);
    } else {
        set_order_status($order['id'], $order['site'], $status_success);
    }

    log_to_file($r);
    log_to_file('Код ответа: ' . $code);
} elseif ($_POST['action'] == 'cancel') {
    $payload = [
        'filter' => [[
            'type' => 'eq',
            'field' => 'extId',
            'value' => $_POST['orderId']
        ]]
    ];
    $oa_order = send_request($oa_base . 'products/order', $payload)['_embedded']['order'];

    if (sizeof($oa_order) == 0) {
        log_to_file('Заказ ' . $_POST['orderId'] . ' не найден в ФФ');
        die();
    }
    $oa_order = $oa_order[0];
    log_to_file('Отмена заказа ' . $oa_order['id'] . ' (' . $_POST['orderId'] . ')');

    $payload = [
        'state' => 'cancel'
    ];

    send_request($oa_base . 'products/order/' . $oa_order['id'], null, $payload, null,  'PATCH');
}
