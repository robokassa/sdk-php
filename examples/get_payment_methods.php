<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Robokassa\Robokassa;

/**
 * Пример использования метода getPaymentMethods()
 * Получает список доступных способов оплаты
 */

try {
    $robokassa = new Robokassa([
        'login' => 'merchant_login',
        'password1' => 'password1',
        'password2' => 'password2',
        'hashType' => 'md5'
    ]);

    $methods = $robokassa->getPaymentMethods();
    echo "Доступные методы оплаты:\n";
    print_r($methods);

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
