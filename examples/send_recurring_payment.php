<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * Пример использования метода payment()->sendRecurring()
 *
 * Метод создаёт дочерний рекуррентный платёж по уже оплаченной материнской
 * операции. У Merchant/Recurring нет тестового режима, поэтому запуск этого
 * примера может создать боевое списание.
 *
 * Перед запуском задайте:
 * ROBOKASSA_PREVIOUS_INVOICE_ID — InvoiceID оплаченного материнского платежа
 * ROBOKASSA_RECURRING_INVOICE_ID — новый InvoiceID дочернего платежа
 * ROBOKASSA_RECURRING_OUT_SUM — сумма дочернего платежа
 */

try {
	$previousInvoiceID = (int)($_ENV['ROBOKASSA_PREVIOUS_INVOICE_ID'] ?? 0);
	if ($previousInvoiceID <= 0) {
		throw new InvalidArgumentException('Укажите ROBOKASSA_PREVIOUS_INVOICE_ID с InvoiceID материнского платежа.');
	}

	$invoiceID = (int)($_ENV['ROBOKASSA_RECURRING_INVOICE_ID'] ?? 0);
	if ($invoiceID <= 0) {
		throw new InvalidArgumentException('Укажите ROBOKASSA_RECURRING_INVOICE_ID с новым InvoiceID дочернего платежа.');
	}
	$outSum = $_ENV['ROBOKASSA_RECURRING_OUT_SUM'] ?? '10.00';

	$robokassa = createRobokassa();

	$result = $robokassa->payment()->sendRecurring([
		'OutSum' => $outSum,
		'InvoiceID' => $invoiceID,
		'PreviousInvoiceID' => $previousInvoiceID,
		'Description' => 'Повторная оплата подписки',
	]);

	echo "Ответ Robokassa: $result\n";

} catch (Exception $e) {
	echo "Ошибка: " . $e->getMessage() . "\n";
}
