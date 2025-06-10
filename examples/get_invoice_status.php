<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Robokassa\Robokassa;

/**
 * Пример использования метода opState()
 * Получает текущий статус счёта по его InvoiceID
 */

try {
    $robokassa = new Robokassa([
        'login' => 'merchant_login',
        'password1' => 'password1',
        'password2' => 'password2',
        'hashType' => 'md5'
    ]);

    $invoiceID = 353847485;

    $status = $robokassa->opState($invoiceID);
    echo "Статус счета #$invoiceID:\n";
    print_r($status);

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
