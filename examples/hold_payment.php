<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * Пример жизненного цикла двухстадийного платежа.
 *
 * Перед запуском задайте:
 * ROBOKASSA_HOLD_ACTION — create, confirm, cancel или status
 * ROBOKASSA_HOLD_INVOICE_ID — InvoiceID операции
 * ROBOKASSA_HOLD_OUT_SUM — сумма операции
 * ROBOKASSA_HOLD_RESULT_URL2 — серверный callback для действия create
 *
 * Confirm и Cancel работают только в основном режиме и не должны повторяться
 * автоматически при неоднозначном результате.
 */

try {
	$action = strtolower($_ENV['ROBOKASSA_HOLD_ACTION'] ?? 'create');
	$invoiceID = (int)($_ENV['ROBOKASSA_HOLD_INVOICE_ID'] ?? 0);
	if ($invoiceID <= 0) {
		throw new InvalidArgumentException('Укажите положительный ROBOKASSA_HOLD_INVOICE_ID.');
	}
	$outSum = $_ENV['ROBOKASSA_HOLD_OUT_SUM'] ?? '10.00';
	$robokassa = createRobokassa();

	switch ($action) {
		case 'create':
			$resultUrl2 = $_ENV['ROBOKASSA_HOLD_RESULT_URL2'] ?? '';
			if ($resultUrl2 === '') {
				throw new InvalidArgumentException('Укажите ROBOKASSA_HOLD_RESULT_URL2.');
			}
			$url = $robokassa->payment()->sendHold([
				'InvId' => $invoiceID,
				'OutSum' => $outSum,
				'Description' => 'Двухстадийная оплата заказа #' . $invoiceID,
				'AdditionalParameters' => [
					'ResultURL2' => $resultUrl2,
				],
			]);
			echo "Ссылка на оплату с холдированием: $url\n";
			break;

		case 'confirm':
			$accepted = $robokassa->payment()->confirmHold($invoiceID, $outSum);
			echo $accepted
				? "Запрос подтверждения принят. Проверьте итоговый статус операции.\n"
				: "Запрос подтверждения отклонён. Не повторяйте его автоматически; проверьте статус.\n";
			break;

		case 'cancel':
			$accepted = $robokassa->payment()->cancelHold($invoiceID, $outSum);
			echo $accepted
				? "Запрос отмены принят. Проверьте итоговый статус операции.\n"
				: "Запрос отмены отклонён. Не повторяйте его автоматически; проверьте статус.\n";
			break;

		case 'status':
			print_r($robokassa->webService()->opState($invoiceID));
			break;

		default:
			throw new InvalidArgumentException('ROBOKASSA_HOLD_ACTION должен быть create, confirm, cancel или status.');
	}
} catch (Throwable $e) {
	echo 'Ошибка: ' . $e->getMessage() . "\n";
}
