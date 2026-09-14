# Robokassa SDK для PHP

SDK для интеграции с платёжной системой Robokassa на PHP.

Текущий основной способ создания платёжной ссылки — `payment()->sendJwt()`. Старый метод `payment()->sendCurl()` сохранён только для обратной совместимости.

## Установка

```sh
composer require robokassa/sdk-php
```

## Создание клиента

```php
<?php

use Robokassa\Client\HttpClient;
use Robokassa\Robokassa;

$robokassa = new Robokassa(
	[
		'login' => getenv('ROBOKASSA_LOGIN') ?: '',
		'password1' => getenv('ROBOKASSA_PASSWORD1') ?: '',
		'password2' => getenv('ROBOKASSA_PASSWORD2') ?: '',
		'hashType' => 'md5',
	],
	new HttpClient()
);
```

Поддерживаемые алгоритмы подписи:

```text
md5, ripemd160, sha1, sha256, sha384, sha512
```

Если передан неизвестный алгоритм, SDK выбросит `Robokassa\Exception\RobokassaException`.

## Доступные методы

| Метод | Описание                                                                     | Документация |
| --- |------------------------------------------------------------------------------| --- |
| `payment()->sendJwt(array $params): string` | Рекомендуемый способ. Создаёт ссылку на оплату через JWT-интерфейс.          | [Invoice API](https://docs.robokassa.ru/ru/invoice-api) |
| `payment()->sendSplit(array $params): string` | Создаёт счёт со сплитованием платежа.                     | [Сплитование платежей](https://docs.robokassa.ru/ru/split-payments) |
| `payment()->sendSavedCard(array $params): string` | Создаёт счёт для оплаты по сохранённой банковской карте через JWT-интерфейс. | [Оплата по сохраненной карте](https://docs.robokassa.ru/ru/saving) |
| `payment()->sendHold(array $params): string` | Создаёт счёт для двухстадийной оплаты.                                       | [Холдирование](https://docs.robokassa.ru/ru/holding.html) |
| `payment()->confirmHold(int $invoiceID, string $outSum, ?array $receipt = null): bool` | Подтверждает списание удержанных средств.                                    | [Холдирование](https://docs.robokassa.ru/ru/holding.html#request) |
| `payment()->cancelHold(int $invoiceID, string $outSum): bool` | Отменяет холдирование.                                                       | [Холдирование](https://docs.robokassa.ru/ru/holding.html#cancel) |
| `payment()->sendRecurring(array $params): string` | Создаёт дочерний рекуррентный платёж по оплаченной материнской операции.     | [Периодические платежи](https://docs.robokassa.ru/ru/recurring-payments) |
| `status()->getInvoiceInformationList(array $filters): array` | Получает список выставленных счетов по фильтрам.                             | [Invoice API](https://docs.robokassa.ru/ru/invoice-api) |
| `webService()->getPaymentMethods(string $lang = 'en'): array` | Получает список доступных способов оплаты.                                   | [XML-интерфейсы](https://docs.robokassa.ru/ru/xml-interfaces) |
| `webService()->opState(int $invoiceID): array` | Получает статус оплаты по `InvoiceID`.                                       | [XML-интерфейсы](https://docs.robokassa.ru/ru/xml-interfaces) |
| `receipt()->sendSecondCheck(array $payload): string` | Отправляет запрос на формирование второго чека.                              | [Второй чек](https://docs.robokassa.ru/ru/second-receipt.html) |
| `receipt()->getCheckStatus(array $payload): array` | Получает статус фискального чека.                                            | [Второй чек](https://docs.robokassa.ru/ru/second-receipt.html) |

## Создание ссылки на оплату через JWT

```php
$url = $robokassa->payment()->sendJwt([
	'OutSum' => 100.00,
	'InvId' => 123456,
	'Description' => 'Оплата заказа #123456',
	'Culture' => 'ru',
]);
```

Метод возвращает строку со ссылкой на оплату.

## Сплитование платежа

Сплитование передаётся через `CreateInvoice` в строковом параметре `AdditionalParameters.Split`. Для удобства передайте в `sendSplit()` нативный PHP-массив участников верхнеуровневым параметром `Split` — SDK проверит его, сериализует в JSON и добавит в `AdditionalParameters`:

```php
$url = $robokassa->payment()->sendSplit([
	'OutSum' => 700.00,
	'InvId' => 500001,
	'Description' => 'Оплата заказа #500001',
	'ExpirationDate' => '2026-12-31T23:59:59+03:00',
	'Aliases' => ['BankCard', 'SBP'],
	'Split' => [
		[
			'id' => 'master-shop',
			'InvoiceId' => 500001,
			'amount' => 500,
			'receipt' => [
				'sno' => 'osn',
				'items' => [
					[
						'name' => 'Товар 1',
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
			'id' => 'partner-shop',
			'amount' => 200,
		],
	],
	'AdditionalParameters' => [
		'Email' => 'buyer@example.com',
	],
]);
```

В JWT значение будет выглядеть следующим образом:

```php
'AdditionalParameters' => [
	'Email' => 'buyer@example.com',
	'Split' => '[{"id":"master-shop","InvoiceId":500001,"amount":500,...}]',
]
```

`Split` не нужно кодировать в JSON или URL-кодировать самостоятельно. Если требуется передать уже подготовленную JSON-строку вручную, используйте универсальный `sendJwt()`. Сплитование несовместимо только с тестовым режимом `IsTest`; остальные параметры `CreateInvoice` метод не изменяет.

## Оплата по сохранённой карте

Для оплаты по сохранённой карте нужен `OpKey` прошлой операции, где покупатель уже использовал банковскую карту. Получите его из уведомления `ResultUrl2` или через `webService()->opState()`, сохраните в своей системе и передайте как `Token` при создании нового счёта:

```php
$url = $robokassa->payment()->sendSavedCard([
	'OutSum' => 100.00,
	'InvId' => 300001,
	'Description' => 'Оплата заказа #300001',
	'Token' => $opKey,
	'AdditionalParameters' => [
		'Email' => 'buyer@example.com',
	],
]);
```

SDK передаст токен в поле `Token` внутри массива `AdditionalParameters`:

```php
'AdditionalParameters' => [
	'Token' => $opKey,
]
```

Если `AdditionalParameters` уже содержит другие значения, они сохранятся. `Token` нельзя совмещать с `Recurring` и `StepByStep` в одном счёте.

## Холдирование

Опция должна быть предварительно подключена для магазина и работает только с платежами банковскими картами. Для создания двухстадийного платежа используйте `sendHold()`:

```php
$url = $robokassa->payment()->sendHold([
	'InvId' => 400001,
	'OutSum' => '100.00',
	'Description' => 'Оплата заказа #400001',
	'AdditionalParameters' => [
		'ResultURL2' => 'https://example.com/robokassa/result2',
	],
]);
```

SDK создаст одноразовый счёт и самостоятельно добавит строковый параметр:

```php
'AdditionalParameters' => [
	'StepByStep' => 'true',
]
```

`StepByStep` нельзя совмещать с `Recurring` и `Token`. Уведомление о переходе операции в `HOLD` поступает на `ResultURL2`; подпись входящего JWS необходимо проверить до изменения состояния заказа.

После получения состояния `HOLD` подтвердите списание:

```php
$accepted = $robokassa->payment()->confirmHold(400001, '100.00');
```

При необходимости в третьем аргументе можно передать обновлённый чек. Сумму и состав корзины разрешено изменять только в меньшую сторону:

```php
$accepted = $robokassa->payment()->confirmHold(400001, '90.00', $updatedReceipt);
```

Для отмены холда:

```php
$accepted = $robokassa->payment()->cancelHold(400001, '100.00');
```

Возвращаемое значение показывает только, принят ли запрос Robokassa. Оно не является конечным статусом операции. После Confirm или Cancel проверьте состояние через `webService()->opState()`:

```php
$state = $robokassa->webService()->opState(400001);
```

Основные коды состояния холда: `20` — средства удержаны, `50` — операция обрабатывается, `60` — холд отменён, `100` — списание подтверждено. При `false` или сетевой ошибке не повторяйте Confirm/Cancel автоматически: сначала запросите состояние операции. Confirm и Cancel не поддерживают тестовый режим.

## Рекуррентные платежи

Для материнского платежа создайте обычный счёт через `sendJwt()` и передайте `Recurring=true` в `AdditionalParameters`:

```php
$url = $robokassa->payment()->sendJwt([
	'OutSum' => 100.00,
	'InvId' => 200001,
	'Description' => 'Оплата подписки',
	'AdditionalParameters' => [
		'Recurring' => 'true',
	],
]);
```

После успешной оплаты материнского платежа можно создать дочерний платёж:

```php
$result = $robokassa->payment()->sendRecurring([
	'OutSum' => '100.00',
	'InvoiceID' => 200002,
	'PreviousInvoiceID' => 200001,
	'Description' => 'Повторная оплата подписки',
]);
```

Метод возвращает текстовый ответ Robokassa, например `OK200002`. Такой ответ означает создание дочерней операции, а не гарантированное успешное списание. Итоговый статус проверяйте через `ResultURL`/`ResultUrl2` или XML-интерфейс в боевом режиме.

У `Merchant/Recurring` нет тестового режима. Если клиент SDK создан с `is_test => true`, `sendRecurring()` выбросит исключение.

## Получение статуса счетов

```php
$result = $robokassa->status()->getInvoiceInformationList([
	'CurrentPage' => 1,
	'PageSize' => 10,
	'InvoiceStatuses' => ['paid', 'expired', 'notpaid'],
	'DateFrom' => '2024-01-01',
	'DateTo' => '2024-01-31',
	'InvoiceTypes' => ['onetime', 'reusable'],
]);
```

Прямое создание сервиса статусов остаётся рабочим для старого кода:

```php
use Robokassa\Service\StatusService;

$status = new StatusService($httpClient, $login, $password1);
```

## XML-интерфейсы

```php
$methods = $robokassa->webService()->getPaymentMethods('ru');
$state = $robokassa->webService()->opState(123456);
```

## Второй чек

```php
$result = $robokassa->receipt()->sendSecondCheck($payload);
$status = $robokassa->receipt()->getCheckStatus([
	'merchantId' => 'merchant',
	'id' => '123456',
]);
```

## Обратная совместимость: sendCurl()

`payment()->sendCurl(array $params): string` помечен как `@deprecated`, будет удалён в следующей major версии. Используйте `payment()->sendJwt()`.

Метод оставлен без runtime warning, чтобы не ломать существующие интеграции.

```php
$url = $robokassa->payment()->sendCurl([
	'OutSum' => 100.00,
	'InvoiceID' => 123456,
	'Description' => 'Оплата заказа #123456',
]);
```

## Примеры

Основные примеры находятся в папке [`examples/`](./examples):

* [`send_payment_jwt.php`](./examples/send_payment_jwt.php) — создание ссылки на оплату через JWT.
* [`send_split_payment.php`](./examples/send_split_payment.php) — создание счёта со сплитованием.
* [`send_saved_card_payment.php`](./examples/send_saved_card_payment.php) — создание счёта для оплаты по сохранённой карте.
* [`hold_payment.php`](./examples/hold_payment.php) — создание, подтверждение, отмена и проверка двухстадийного платежа.
* [`send_recurring_payment.php`](./examples/send_recurring_payment.php) — создание дочернего рекуррентного платежа по оплаченной материнской операции.
* [`get_invoice_information.php`](./examples/get_invoice_information.php) — получение списка счетов через `$robokassa->status()`.
* [`get_payment_methods.php`](./examples/get_payment_methods.php) — получение доступных способов оплаты.
* [`get_invoice_status.php`](./examples/get_invoice_status.php) — проверка статуса оплаты через XML-интерфейс.
* [`send_second_check.php`](./examples/send_second_check.php) — отправка второго чека.
* [`get_check_status.php`](./examples/get_check_status.php) — проверка статуса чека.

Устаревший пример для обратной совместимости:

* [`send_payment_curl.php`](./examples/send_payment_curl.php) — старый способ создания ссылки через `sendCurl()`.

## Проверка

```sh
composer validate --strict
vendor/bin/phpunit
```
