<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Robokassa\Robokassa;

/**
 * Пример использования метода getCheckStatus()
 * Получает статус фискализации чека по InvId
 */

$robokassa = new Robokassa([
    'login'     => 'merchant_login',
    'password1' => 'password1',
    'password2' => 'password2',
    'hashType'  => 'md5'
]);

$payload = [
    'merchantId' => 'merchant_login',
    'id'         => 'InvId'
];

try {
    $status = $robokassa->getCheckStatus($payload);

    echo "Ответ Robokassa (Status):\n";
    echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage();
}

