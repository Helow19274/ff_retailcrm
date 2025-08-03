<?php
$retail_api_key = '';
$cdek_key1 = '';
$cdek_key2 = '';
$oa_login = '';
$oa_password = '';
$oa_warehouses = [
    'sklad-1' => [0, 0, 0],
];

$retail_base = 'https://retail.retailcrm.ru/api/v5/';
$cdek_base = 'https://api.cdek.ru/v2/';
$oa_base = 'https://cdek.orderadmin.ru/api/';
$status_success = 'ff-created';
$status_failed = 'ff-not-created';
$tracking_number_field = 'ff_tracking_number';
$statuses = [
    'pending_error' => 'ff-error',
    'pending' => 'no-product',
    'partly_reserved' => 'no-product',
    'assembling' => 'assembling',
    'assembled' => 'send-to-delivery',
    'delivery' => 'delivering',
    'processing' => 'delivering',
    'complete' => 'complete',
    'cancel' => 'cancel-other'
];

function send_request($url, $query = null, $body = null, $headers = null, $type='GET', $return_ch = false, $log = false) {
    global $oa_base, $retail_base;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $h = [];
    if ($headers != null) {
        $h = $headers;
    }

    if (strpos($url, $retail_base) === 0) {
        global $retail_api_key;
        if ($query == null) {
            $query = ['apiKey' => $retail_api_key];
        } else {
            $query['apiKey'] = $retail_api_key;
        }
    }

    if ($query != null) {
        $url .= '?'.http_build_query($query);
    }
    curl_setopt($ch, CURLOPT_URL, $url);

    if ($body != null) {
        if (gettype($body) == 'array') {
            $h[] = 'Content-type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } else {
            $h[] = 'Content-type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }

    if (strpos($url, $oa_base) === 0) {
        global $oa_login, $oa_password;
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $oa_login . ':' . $oa_password);
    }

    if ($log) {
        log_to_file('Отправка HTTP запроса');
        log_to_file('URL: ' . $url);
        log_to_file('Query: ' . $query);
        log_to_file('Body: ' . $body);
        log_to_file('Headers: ' . $headers);
        log_to_file('Headers 2: ' . $h);
        log_to_file('Method: ' . $type);
        log_to_file('Raw: ' . $return_ch);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    if ($return_ch) {
        return $ch;
    }

    $r = curl_exec($ch);
    curl_close($ch);

    if ($log) {
        log_to_file('Ответ:');
        log_to_file($r);
    }

    return json_decode($r, true);
}

function set_order_status($order_id, $site_id, $status, $comment=null) {
    global $retail_base;
    $payload = [
        'by' => 'id',
        'site' => $site_id,
        'order' => [
            'status' => $status,
            'statusComment' => $comment
        ]
    ];
    $payload['order'] = json_encode($payload['order']);
    $payload = http_build_query($payload);
    send_request($retail_base . 'orders/' . $order_id . '/edit', null, $payload, null, 'POST');
}

function log_to_file($text, $filename='log.txt') {
    file_put_contents($filename, "\n" . print_r($text, true), FILE_APPEND);
}
