<?php

namespace Robokassa;

use Exception;
use GuzzleHttp\Client;

class Robokassa
{

    private $httpClient;

    /**
     * @var string
     */
    private string $paymentUrl = 'https://auth.robokassa.ru/Merchant/Index/';

    /**
     * @var string
     */
    private string $paymentCurl = 'https://auth.robokassa.ru/Merchant/Indexjson.aspx';

    /**
     * @var string
     */
    private string $recurrentUrl = 'https://auth.robokassa.ru/Merchant/Recurring';

    /**
     * @var string
     */
    private string $jwtApiUrl = 'https://services.robokassa.ru/InvoiceServiceWebApi/api/CreateInvoice';

    /**
     * @var string
     */
    private string $webServiceUrl = 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx';

    private const SECOND_CHECK_URL = 'https://ws.roboxchange.com/RoboFiscal/Receipt/Attach';

    /**
     * @var bool
     */
    private $is_test = false;

    /**
     * @var string
     */
    protected $password1;

    /**
     * @var string
     */
    protected $password2;

    /**
     * @var string
     */
    private $hashType = 'md5';

    /**
     * @var mixed
     */
    private $login;

    /**
     * @var array|string[]
     */
    private array $hashAlgoList = [
        'md5',
        'ripemd160',
        'sha1',
        'sha256',
        'sha384',
        'sha512'
    ];

    /**
     * Robokassa constructor.
     * @param $params
     * @throws Exception
     */
    public function __construct($params)
    {

        $this->httpClient = new Client();

        if (empty($params)) {
            throw new Exception('Params is not defined');
        }

        if (empty($params['login'])) {
            throw new Exception('Param login is not defined');
        }

        if (empty($params['password1'])) {
            throw new Exception('Param password1 is not defined');
        }

        if (empty($params['password2'])) {
            throw new Exception('Param password2 is not defined');
        }

        if (!empty($params['is_test'])) {
            if (empty($params['test_password1'])) {
                throw new Exception('Param test_password1 is not defined');
            }

            if (empty($params['test_password2'])) {
                throw new Exception('Param test_password2 is not defined');
            }

            $this->is_test = $params['is_test'];
        }

        if (!empty($params['hashType'])) {
            if (!in_array($params['hashType'], $this->hashAlgoList)) {
                $except = implode(', ', $this->hashAlgoList);
                throw new Exception("The hashType parameter can only the values: $except");
            }

            $this->hashType = $params['hashType'];
        }

        $this->login = $params['login'];
        $this->password1 = $this->is_test ? $params['test_password1'] : $params['password1'];
        $this->password2 = $this->is_test ? $params['test_password2'] : $params['password2'];

    }

    /**
     * Send payment request via CURL
     *
     * @param array $params
     * @return string
     * @throws Exception
     */
    public function sendPaymentRequestCurl(array $params): string
    {
        if (empty($params['OutSum']) || empty($params['Description'])) {
            throw new Exception('Required parameters are missing: OutSum, Description');
        }

        $params['MerchantLogin'] = $this->getLogin();
        $signatureParams = [
            'OutSum' => $params['OutSum'],
            'InvoiceID' => $params['InvoiceID'] ?? '',
        ];

        if (!empty($params['Receipt'])) {
            $signatureParams['Receipt'] = urlencode(json_encode($params['Receipt']));
            $params['Receipt'] = urlencode($signatureParams['Receipt']);
        }

        if ($this->is_test) {
            $params['IsTest'] = '1';
        }

        $fields = $this->getFields($params);

        if (!empty($fields)) {
            $signatureParams = array_merge($signatureParams, $fields);

            foreach ($fields as $name => $value) {
                $params[$name] = urlencode($value);
            };
        }

        $params['SignatureValue'] = $this->generateSignature($signatureParams);

        try {
            $response = $this->httpClient->post($this->paymentCurl, [
                'form_params' => $params,
            ]);

            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody()->getContents(), true);

                if (!empty($responseData['invoiceID'])) {
                    return $this->paymentUrl . $responseData['invoiceID'];
                }

                throw new Exception('Invoice ID not found in response.');
            }

            throw new Exception('Failed to send payment request. HTTP Status: ' . $response->getStatusCode());
        } catch (Exception $e) {
            throw new Exception('CURL request failed: ' . $e->getMessage());
        }
    }

    /**
     * Отправляет запрос на создание счёта через Robokassa JWT-интерфейс
     *
     * @param array $params Параметры платежа:
     *  - OutSum (float, обяз.)
     *  - InvId (int, обяз.)
     *  - Description (string, опц.)
     *  - MerchantComments (string, опц.)
     *  - InvoiceItems (array, опц.)
     *  - UserFields (array, опц.)
     *  - SuccessUrl2Data (array, опц.)
     *  - FailUrl2Data (array, опц.)
     *  - InvoiceType (string, опц.)
     *  - Culture (string, опц.)
     *
     * @return string URL для оплаты счёта
     * @throws Exception
     */
    public function sendPaymentRequestJwt(array $params): string
    {
        if (empty($params['OutSum']) || !isset($params['InvId'])) {
            throw new Exception('Required parameters: OutSum, InvId');
        }

        $merchantLogin = $this->getLogin();
        $password1 = $this->getPassword1();

        $header = ['alg' => 'MD5', 'typ' => 'JWT'];

        $payload = [
            'MerchantLogin' => $merchantLogin,
            'InvoiceType'   => $params['InvoiceType'] ?? 'OneTime',
            'Culture'       => $params['Culture'] ?? 'ru',
            'InvId'         => (int) $params['InvId'],
            'OutSum'        => (float) $params['OutSum'],
        ];

        if (!empty($params['Description'])) {
            $payload['Description'] = $params['Description'];
        }

        if (!empty($params['MerchantComments'])) {
            $payload['MerchantComments'] = $params['MerchantComments'];
        }

        if (!empty($params['InvoiceItems'])) {
            $payload['InvoiceItems'] = $params['InvoiceItems'];
        }

        if (!empty($params['UserFields']) && is_array($params['UserFields'])) {
            $payload['UserFields'] = $params['UserFields'];
        }

        if (!empty($params['SuccessUrl2Data'])) {
            $payload['SuccessUrl2Data'] = $params['SuccessUrl2Data'];
        }

        if (!empty($params['FailUrl2Data'])) {
            $payload['FailUrl2Data'] = $params['FailUrl2Data'];
        }

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_UNICODE));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $dataToSign = $encodedHeader . '.' . $encodedPayload;

        $signatureRaw = hash_hmac('md5', $dataToSign, $merchantLogin . ':' . $password1, true);
        $encodedSignature = $this->base64UrlEncode($signatureRaw);

        $jwt = $dataToSign . '.' . $encodedSignature;

        try {
            $response = $this->httpClient->post(
                $this->jwtApiUrl,
                [
                    'body' => json_encode($jwt),
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                ]
            );

            $bodyRaw = $response->getBody()->getContents();
            $responseData = json_decode($bodyRaw, true);

            if (!empty($responseData['url'])) {
                return $responseData['url'];
            }

            throw new Exception('JWT request failed: ' . $bodyRaw);

        } catch (Exception $e) {
            if ($e instanceof \GuzzleHttp\Exception\ClientException) {
                $response = $e->getResponse();
                $body = $response ? $response->getBody()->getContents() : null;
                throw new Exception('JWT request failed: ' . $body);
            }

            throw new Exception('JWT request failed: ' . $e->getMessage());
        }
    }

    /**
     * Получение списка доступных способов оплаты
     *
     * Возвращает список способов оплаты, доступных для оплаты заказов указанного магазина/сайта.
     * @param $lang
     * @return array
     * @throws Exception
     */
    public function getPaymentMethods(string $lang = 'en'): array
    {
        if (empty($lang)) {
            throw new Exception('Param lang is not defined');
        }

        $query = http_build_query([
            'MerchantLogin' => $this->getLogin(),
            'Language' => $lang,
        ]);

        $url = $this->getWebServiceUrl('GetPaymentMethods', $query);

        return $this->getRequest($url);
    }

    /**
     * Получение состояния оплаты счета
     *
     * Возвращает детальную информацию о текущем состоянии и реквизитах оплаты.
     * Необходимо помнить, что операция инициируется не в момент ухода пользователя на оплату,
     * а позже – после подтверждения его платежных реквизитов,
     * т.е. Вы вполне можете не находить операцию, которая по Вашему мнению уже должна начаться.
     * @param $invoiceID
     * @return array
     * @throws Exception
     */
    public function opState($invoiceID): array
    {
        if (empty($invoiceID)) {
            throw new Exception('Param invoiceID is not defined');
        }

        $query = http_build_query([
            'MerchantLogin' => $this->getLogin(),
            'InvoiceID' => $invoiceID,
            'Signature' => $this->signatureState($invoiceID)
        ]);

        $url = $this->getWebServiceUrl('OpState', $query);

        return $this->getRequest($url);
    }

    /**
     * Подпись для запроса проверки статуса счета
     *
     * @param $invoiceID
     * @return string
     */
    private function signatureState($invoiceID): string
    {
        return hash($this->getHashType(), "{$this->getLogin()}:$invoiceID:{$this->getPassword2()}");
    }

    /**
     * @param $params
     * @return array
     */
    private function getFields($params): array
    {
        $fields = [];

        foreach ($params as $key => $value) {
            if (!preg_match('~^Shp_~iu', $key)) {
                continue;
            }

            $fields[$key] = urlencode($value);
        }

        ksort($fields);

        return $fields;
    }

    /**
     * @param $params
     * @param $required
     * @return string
     */
    private function getHashFields($params, $required): string
    {
        $fields = [];

        foreach ($params as $key => $value) {
            if (!preg_match('~^Shp_~iu', $key)) {
                continue;
            }

            $required[] = $key . '=' . $value;
        }

        $hash = implode(':', $required);

        if (!empty($fields)) {
            $hash .= ':' . implode(':', $fields);
        }

        return $hash;
    }

    /**
     * Подпись для запроса оплаты
     *
     * @param $params
     * @return string
     */
    private function generateSignature($params): string
    {
        $required = [
            $this->getLogin(),
            $params['OutSum'],
            $params['InvoiceID'],
        ];

        if (!empty($params['Receipt'])) {
            $required[] = $params['Receipt'];
        }

        array_push($required, $this->getPassword1());

        $hash = $this->getHashFields($params, $required);

        return hash($this->getHashType(), $hash);
    }

    /**
     * @param $url
     * @return array
     */
    private function getRequest(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url);

            if ($response->getStatusCode() === 200) {
                $xml = $response->getBody()->getContents();
                return $this->getXmlInArray($xml);
            }

            throw new Exception("Ошибка запроса: HTTP " . $response->getStatusCode());
        } catch (Exception $e) {
            throw new Exception("Ошибка запроса: " . $e->getMessage());
        }
    }


    /**
     * @param $response
     * @return array
     */
    private function getXmlInArray($response): array
    {
        $res = simplexml_load_string($response);
        $res = json_decode(json_encode((array)$res, JSON_NUMERIC_CHECK), true);

        return $res;
    }

    /**
     * @param $segment
     * @param $query
     * @return string
     */
    private function getWebServiceUrl($segment, $query): string
    {
        return $this->webServiceUrl . '/' . $segment . '?' . $query;
    }

    /**
     * @return string
     */
    private function getLogin(): string
    {
        return $this->login;
    }

    /**
     * @return string
     */
    private function getPassword1(): string
    {
        return $this->password1;
    }

    /**
     * @return string
     */
    private function getPassword2(): string
    {
        return $this->password2;
    }

    /**
     * @return string
     */
    private function getHashType(): string
    {
        return $this->hashType;
    }

    /**
     * Кодирует строку в формат base64 URL (без =, + и /)
     *
     * @param string $data
     * @return string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Генерация строки второго чека: base64(payload).base64(signature)
     *
     * @param array $payload
     * @return string
     * @throws Exception
     */
    public function getSecondCheckUrl(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new Exception("Ошибка кодирования JSON");
        }

        $base64Payload = $this->base64UrlEncode($json);
        $hashString = $base64Payload . $this->password1;

        $type = strtolower($this->hashType);

        switch ($type) {
            case 'sha256':
                $hash = hash('sha256', $hashString);
                break;

            case 'sha512':
                $hash = hash('sha512', $hashString);
                break;

            default:
                $hash = md5($hashString);
                break;
        }

        $base64Signature = $this->base64UrlEncode($hash);

        return $base64Payload . '.' . $base64Signature;
    }

    public function sendSecondCheck(array $payload): string
    {
        $body = $this->getSecondCheckUrl($payload);

        try {
            $response = $this->httpClient->post(self::SECOND_CHECK_URL, [
                'body'    => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            return $response->getBody()->getContents();

        } catch (ClientException $e) {
            $resp    = $e->getResponse();
            $content = $resp ? $resp->getBody()->getContents() : $e->getMessage();
            throw new \Exception('Ошибка при отправке второго чека: ' . $content);

        } catch (\Exception $e) {
            throw new \Exception('Ошибка при отправке второго чека: ' . $e->getMessage());

        }
    }
}