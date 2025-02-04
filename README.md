# Robokassa SDK (PHP)

SDK для интеграции с платежной системой **Robokassa** в PHP.  
Позволяет отправлять платежные запросы, получать статус оплаты и список доступных методов оплаты.

## 📦 Установка
Установите SDK через **Composer**:
```sh
composer require robokassa/sdk-php
```

## 🚀 Доступные методы
| Метод | Описание |
|--------|----------|
| `sendPaymentRequestCurl(array $params): string` | Создает ссылку на оплату |
| `getPaymentMethods(string $lang = 'ru'): array` | Получает доступные методы оплаты |
| `opState(int $invoiceID): array` | Получает статус оплаты по `InvoiceID` |

⚡ **В будущем будут добавлены новые методы**.

## 🔍 Примеры использования
Примеры кода находятся в папке **`examples/`**.

### 🔗 Создание ссылки на оплату
```php
$robokassa = new Robokassa([
    'login' => 'merchant_login',
    'password1' => 'password1',
    'password2' => 'password2',
    'hashType' => 'md5'
]);

$params = [
        'OutSum' => 100,
        'InvoiceID' => 88512512,
        'Description' => 'Description text',
        'Receipt' => [
            'items' => [
                [
                    'name' => 'Product name',
                    'quantity' => 1,
                    'sum' => 100,
                    'payment_method' => 'full_payment',
                    'payment_object' => 'commodity',
                    'tax' => 'none'
                ]
            ]
        ]
    ];

$paymentUrl = $robokassa->sendPaymentRequestCurl($params);
echo "Ссылка для оплаты: $paymentUrl";
```

### 🛠 Получение доступных методов оплаты
```php
$methods = $robokassa->getPaymentMethods();
print_r($methods);
```

### 🔍 Проверка статуса оплаты
```php
$status = $robokassa->opState(88512512);
print_r($status);
```

## 📌 Дополнительно
- SDK активно развивается, в будущем **будут добавлены новые методы**.
- Официальная документация Robokassa: [https://docs.robokassa.ru/](https://docs.robokassa.ru/).