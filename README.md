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

| Метод | Описание | Документация |
| --- | --- | --- |
| `payment()->sendJwt(array $params): string` | Рекомендуемый способ. Создаёт ссылку на оплату через JWT-интерфейс. | [Invoice API](https://docs.robokassa.ru/ru/invoice-api) |
| `status()->getInvoiceInformationList(array $filters): array` | Получает список выставленных счетов по фильтрам. | [Invoice API](https://docs.robokassa.ru/ru/invoice-api) |
| `webService()->getPaymentMethods(string $lang = 'en'): array` | Получает список доступных способов оплаты. | [XML-интерфейсы](https://docs.robokassa.ru/ru/xml-interfaces) |
| `webService()->opState(int $invoiceID): array` | Получает статус оплаты по `InvoiceID`. | [XML-интерфейсы](https://docs.robokassa.ru/ru/xml-interfaces) |
| `receipt()->sendSecondCheck(array $payload): string` | Отправляет запрос на формирование второго чека. | [Второй чек](https://docs.robokassa.ru/ru/second-receipt.html) |
| `receipt()->getCheckStatus(array $payload): array` | Получает статус фискального чека. | [Второй чек](https://docs.robokassa.ru/ru/second-receipt.html) |

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
