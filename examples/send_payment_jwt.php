<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * Пример использования метода payment()->sendJwt()
 * Создаёт платёжную ссылку через JWT-интерфейс (рекомендуемый способ)
 */

try {
	$robokassa = createRobokassa();

	$params = [
		'InvId' => 133765623,
		'OutSum' => 10,
		'Description' => 'Оплата тестового заказа',
		'MerchantComments' => 'Без комментариев',
		'InvoiceType' => 'Reusable',
		'Culture' => 'ru',
		'InvoiceItems' => [
			[
				'Name' => 'Тестовый товар 1',
				'Quantity' => 1,
				'Cost' => 10,
				'Tax' => 'vat0',
				'PaymentMethod' => 'full_payment',
				'PaymentObject' => 'commodity',
			]
		],
		'UserFields' => [
			'shp_info' => 'test',
			'shp_user' => 'admin',
		],
		'SuccessUrl2Data' => [
			'Url' => 'https://example.com/success',
			'Method' => 'GET',
		],
		'FailUrl2Data' => [
			'Url' => 'https://example.com/fail',
			'Method' => 'POST',
		],
	];

	$url = $robokassa->payment()->sendJwt($params);
	echo "Ссылка на оплату (JWT): $url\n";

} catch (Exception $e) {
	echo "Ошибка: " . $e->getMessage() . "\n";
}
