<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * Пример использования метода payment()->sendSavedCard().
 *
 * Token — это OpKey прошлой операции, где покупатель уже использовал банковскую карту.
 * В реальной интеграции магазин хранит OpKey у себя и передаёт его в SDK при создании нового счёта.
 */

try {
	$robokassa = createRobokassa();
	$opKey = $_ENV['ROBOKASSA_SAVED_CARD_OP_KEY'] ?? '';

	if ($opKey === '') {
		throw new RuntimeException('Укажите ROBOKASSA_SAVED_CARD_OP_KEY с OpKey прошлой операции.');
	}

	$url = $robokassa->payment()->sendSavedCard([
		'InvId' => 300001,
		'OutSum' => 100.00,
		'Description' => 'Оплата по сохранённой карте',
		'Token' => $opKey,
		'AdditionalParameters' => [
			'Email' => 'buyer@example.com',
		],
	]);

	echo "Ссылка на оплату по сохранённой карте: $url\n";

} catch (Throwable $e) {
	echo "Ошибка: " . $e->getMessage() . "\n";
}
