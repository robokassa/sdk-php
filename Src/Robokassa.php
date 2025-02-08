<?php

namespace Robokassa;

use Exception;
use GuzzleHttp\Client;

class Robokassa
{

    /**
     * @var Client $httpClient
     */
    private Client $httpClient;

    /**
     * @var string
     */
    private string $paymentUrl = 'https://auth.robokassa.ru/Merchant/Index.aspx';

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
    private string $webServiceUrl = 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx';


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

        $this->httpClient = new Client([
            'timeout'  => 5.0,
        ]);
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

        $params['SignatureValue'] = $this->generateSignature($signatureParams);

        try {
            $response = $this->httpClient->post('https://auth.robokassa.ru/Merchant/Indexjson.aspx', [
                'form_params' => $params,
            ]);

            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody()->getContents(), true);

                if (!empty($responseData['invoiceID'])) {
                    return 'https://auth.robokassa.ru/Merchant/Index/' . $responseData['invoiceID'];
                }

                throw new Exception('Invoice ID not found in response.');
            }

            throw new Exception('Failed to send payment request. HTTP Status: ' . $response->getStatusCode());
        } catch (Exception $e) {
            throw new Exception('CURL request failed: ' . $e->getMessage());
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

            $fields[$key] = $value;
        }

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

            $fields[] = $key . '=' . $value;
        }

        $hash = implode(':', $required);

        if (!empty($fields)) {
            $hash .= ':' . implode(':', $fields);
        }

        return $hash;
    }

    /**
     * @param $params
     * @param $password
     * @return bool
     */
    private function checkHash($params, $password): bool
    {
        $required = [
            $params['OutSum'],
            $params['InvId'],
            $password
        ];

        $hash = $this->getHashFields($params, $required);

        $crc = strtoupper($params['SignatureValue']);
        $my_crc = strtoupper(hash($this->getHashType(), $hash));

        return $my_crc === $crc;
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
    private function getRequest($url): array
    {
        $response = $this->httpClient->get($url);

        if ($response->getStatusCode() === 200) {
            $xml = $response->getBody()->getContents();
            return $this->getXmlInArray($xml);
        }

        throw new Exception("Ошибка запроса: HTTP " . $response->getStatusCode());
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
}
