<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * Пример использования метода payment()->sendSplit().
 *
 * Сплитование создаётся через Invoice API, но не поддерживает IsTest. Перед
 * запуском используйте реквизиты основного магазина и задайте логин участника
 * в ROBOKASSA_SPLIT_PARTNER_LOGIN.
 */

try {
	$masterLogin = $_ENV['ROBOKASSA_LOGIN'] ?? '';
	$partnerLogin = $_ENV['ROBOKASSA_SPLIT_PARTNER_LOGIN'] ?? '';
	if ($partnerLogin === '') {
		throw new InvalidArgumentException('Укажите ROBOKASSA_SPLIT_PARTNER_LOGIN.');
	}

	$robokassa = createRobokassa();
	$url = $robokassa->payment()->sendSplit([
		'InvId' => 500001,
		'OutSum' => 700,
		'Description' => 'Оплата заказа со сплитованием',
		'ExpirationDate' => '2026-12-31T23:59:59+03:00',
		'Aliases' => ['BankCard', 'SBP'],
		'Split' => [
			[
				'id' => $masterLogin,
				'InvoiceId' => 500001,
				'amount' => 500,
				'receipt' => [
					'sno' => 'osn',
					'items' => [
						[
							'name' => 'Товар мастер-магазина',
							'quantity' => 1,
							'sum' => 500,
							'tax' => 'vat20',
							'payment_method' => 'full_payment',
							'payment_object' => 'commodity',
						],
					],
				],
			],
			[
				'id' => $partnerLogin,
				'amount' => 200,
			],
		],
		'AdditionalParameters' => [
			'Email' => 'buyer@example.com',
		],
	]);

	echo "Ссылка на оплату со сплитованием: $url\n";
} catch (Throwable $e) {
	echo 'Ошибка: ' . $e->getMessage() . "\n";
}
