<?php

function normalizeIPROGPhoneNumber($phone)
{
    $phone = preg_replace('/\D+/', '', (string)$phone);

    // 09XXXXXXXXX -> 639XXXXXXXXX
    if (preg_match('/^09\d{9}$/', $phone)) {
        return '63' . substr($phone, 1);
    }

    // 9XXXXXXXXX -> 639XXXXXXXXX
    if (preg_match('/^9\d{9}$/', $phone)) {
        return '63' . $phone;
    }

    // Already 639XXXXXXXXX
    if (preg_match('/^639\d{9}$/', $phone)) {
        return $phone;
    }

    return $phone;
}

function sendIPROGSMS($phoneNumber, $message)
{
    // ILAGAY DITO ANG ACTUAL IPROG MAIN API TOKEN MO
    $apiToken = '413335a1a3b049589c4c3252079a772282bfb35f';

    $phoneNumber = normalizeIPROGPhoneNumber($phoneNumber);

    // Check only for the placeholder, NOT the real token.
    if (trim($apiToken) === '') {
    return [
        'success' => false,
        'http_code' => 0,
        'response' => 'IPROG API token has not been configured.',
        'phone_number' => $phoneNumber
    ];
}

    if (!preg_match('/^639\d{9}$/', $phoneNumber)) {
        return [
            'success' => false,
            'http_code' => 0,
            'response' => 'Invalid Philippine mobile number format.',
            'phone_number' => $phoneNumber
        ];
    }

    $url = 'https://www.iprogsms.com/api/v1/sms_messages';

    $data = [
        'api_token' => $apiToken,
        'message' => $message,
        'phone_number' => $phoneNumber
    ];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'response' => 'cURL error: ' . $curlError,
            'phone_number' => $phoneNumber
        ];
    }

    $decoded = json_decode($response, true);

    $isSuccess = ($httpCode >= 200 && $httpCode < 300);

    if (is_array($decoded) && isset($decoded['status'])) {
        $isSuccess = $isSuccess && ((int)$decoded['status'] === 200);
    }

    return [
        'success' => $isSuccess,
        'http_code' => $httpCode,
        'response' => $response,
        'phone_number' => $phoneNumber,
        'message_id' => is_array($decoded)
            ? ($decoded['message_id'] ?? null)
            : null
    ];
}
?>
