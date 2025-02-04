<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Robokassa\Robokassa;

try {
    $robokassa = new Robokassa([
        'login' => 'merchant_login',
        'password1' => 'password1',
        'password2' => 'password2',
        'hashType' => 'md5'
    ]);

    // ----- Создание ссылки на оплату -----
    echo str_repeat('-', 50) . "\n";
    echo "===> Метод sendPaymentRequestCurl()\n";

    $params = [
        'OutSum' => 100,
        'InvoiceID' => 88512512,
        'Description' => 'Description text',
        'Receipt' => [
            'items' => [
                [
                    'name' => 'Product name',
                    'quantity' => 1,
                    'sum' => 100,
                    'payment_method' => 'full_payment',
                    'payment_object' => 'commodity',
                    'tax' => 'none'
                ]
            ]
        ]
    ];

    $paymentUrl = $robokassa->sendPaymentRequestCurl($params);
    echo "Ссылка для оплаты: $paymentUrl\n";

    // ----- Получение доступных методов оплаты -----
    echo str_repeat('-', 50) . "\n";
    echo "===> Метод getPaymentMethods()\n";

    $paymentMethods = $robokassa->getPaymentMethods();
    print_r($paymentMethods);

    // ----- Получение статуса оплаты -----
    echo str_repeat('-', 50) . "\n";
    echo "===> Метод opState()\n";

    $invoiceID = 353847485;
    $status = $robokassa->opState($invoiceID);
    print_r($status);

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
