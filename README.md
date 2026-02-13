# Robokassa SDK (PHP)

SDK для интеграции с платёжной системой **Robokassa** на PHP.  
Позволяет отправлять платёжные запросы (включая JWT), проверять статус платежа и получать доступные методы оплаты.

## 📦 Установка

Установите SDK через **Composer**:

```sh
composer require robokassa/sdk-php
````

## 🚀 Доступные методы

| Метод                                           | Описание                                                                      | Документация                                                                                |
|-------------------------------------------------|-------------------------------------------------------------------------------| ------------------------------------------------------------------------------------------- |
| `payment()->sendJwt(array $params): string`  | ✅ Рекомендуемый способ. Создаёт ссылку на оплату через JWT-интерфейс          | [docs.robokassa.ru/ru/invoice-api](https://docs.robokassa.ru/ru/invoice-api)        |
| `payment()->sendCurl(array $params): string` | Создаёт ссылку на оплату через стандартный интерфейс                          | —                                                                                           |
| `webService()->getPaymentMethods(string $lang = 'ru'): array` | Получает список доступных методов оплаты                                      | [docs.robokassa.ru/xml-interfaces/#currency](https://docs.robokassa.ru/xml-interfaces/#currency) |
| `webService()->opState(int $invoiceID): array`                | Получает статус оплаты по `InvoiceID`                                         | [docs.robokassa.ru/xml-interfaces/#account](https://docs.robokassa.ru/xml-interfaces/#account) |
| `status()->getInvoiceInformationList(array $filters): array` | Получает список выставленных счетов с возможностью фильтрации по статусу, дате, сумме и т.д.| [docs.robokassa.ru/invoiceapi/#status](https://docs.robokassa.ru/invoiceapi/#status) |
| `receipt()->sendSecondCheck(array $payload): string`        | Отправляет запрос на формирование второго чека и возвращает ответ             | [docs.robokassa.ru/second-check/#request](https://docs.robokassa.ru/second-check/#request) |
| `receipt()->getCheckStatus(array $payload): array`         | Отправляет запрос на получение статуса фискального чека                       | [docs.robokassa.ru/second-check/#status](https://docs.robokassa.ru/second-check/#status) |

## ⚙️ Настройка окружения

SDK не зависит от дополнительных библиотек для работы с конфигурацией: передавайте логин и пароли так, как это принято в вашем проекте (Laravel, Symfony, Docker, чистый PHP и т.д.). В SDK данные попадают в массив настроек при создании клиента, поэтому вы можете использовать любую существующую систему управления секретами.

### Минимальная настройка для примеров

1. Скопируйте файл `.env.example` в `.env`.
2. Заполните переменные `ROBOKASSA_LOGIN`, `ROBOKASSA_PASSWORD1`, `ROBOKASSA_PASSWORD2`.
3. Запустите нужный файл из папки `examples/`. Файл [`examples/bootstrap.php`](./examples/bootstrap.php) автоматически считывает `.env` и загружает значения в `$_ENV`.

### Использование в собственном приложении

* **Фреймворки (Laravel, Symfony и др.)** — используйте штатные механизмы конфигурации и передавайте значения при создании `Robokassa`.
* **Чистый PHP или Docker** — задайте переменные окружения (например, через `export` или `docker run -e`) либо заполните `$_ENV` любым удобным способом.

```php
$robokassa = new Robokassa(
[
'login'     => getenv('ROBOKASSA_LOGIN') ?: '',
'password1' => getenv('ROBOKASSA_PASSWORD1') ?: '',
'password2' => getenv('ROBOKASSA_PASSWORD2') ?: '',
'hashType'  => 'md5',
],
new HttpClient()
);
```

## 📂 Примеры использования

Полные примеры использования SDK находятся в папке [`examples/`](./examples):

* [`send_payment_jwt.php`](./examples/send_payment_jwt.php) — создание ссылки на оплату через **JWT** (рекомендуется)
* [`send_payment_curl.php`](./examples/send_payment_curl.php) — создание ссылки на оплату через стандартный CURL-интерфейс
* [`get_payment_methods.php`](./examples/get_payment_methods.php) — получение доступных способов оплаты
* [`get_invoice_status.php`](./examples/get_invoice_status.php) — проверка статуса счёта
* [`send_second_check.php`](./examples/send_second_check.php) — отправка второго чека
* [`get_check_status.php`](./examples/get_check_status.php) — проверка статуса чека
* [`get_invoice_information.php`](./examples/get_invoice_information.php) — запрос статуса созданного счета/ссылки

## 📌 Дополнительно

* Метод `payment()->sendJwt()` — предпочтительный способ и рекомендуется к использованию.
* Официальная документация: [docs.robokassa.ru](https://docs.robokassa.ru/)

