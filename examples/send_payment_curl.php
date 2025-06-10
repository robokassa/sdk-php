<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Robokassa\Robokassa;

/**
 * Пример использования метода sendPaymentRequestCurl()
 * Создаёт платёжную ссылку через обычный POST-запрос (не JWT)
 */

try {
    $robokassa = new Robokassa([
        'login' => 'merchant_login',
        'password1' => 'password1',
        'password2' => 'password2',
        'hashType' => 'md5'
    ]);

    $params = [
        'OutSum' => 100.00,
        'InvoiceID' => 123456,
        'Description' => 'Оплата заказа #123456',
        'Culture' => 'ru',
        'Receipt' => [
            'items' => [
                [
                    'name' => 'Товар 1',
                    'quantity' => 1,
                    'sum' => 100.00,
                    'payment_method' => 'full_payment',
                    'payment_object' => 'commodity',
                    'tax' => 'none'
                ]
            ]
        ]
    ];

    $url = $robokassa->sendPaymentRequestCurl($params);
    echo "Ссылка на оплату: $url\n";

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
