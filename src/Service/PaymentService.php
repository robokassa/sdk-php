<?php
namespace Robokassa\Service;

use Robokassa\Client\HttpClientInterface;
use Robokassa\Client\Response;
use Robokassa\Exception\RobokassaException;
use Robokassa\Signature\SignatureService;

class PaymentService {
	private HttpClientInterface $http;
	private SignatureService $sign;
	private string $merchantLogin;
	private string $password1;
	private bool $isTest;
	private string $paymentUrl;
	private string $paymentCurl;
	private string $jwtApiUrl;
	private string $hashType;
	private string $recurringUrl;

	public function __construct(
		HttpClientInterface $http,
		SignatureService $sign,
		string $login,
		string $password1,
		bool $isTest,
		string $paymentUrl,
		string $paymentCurl,
		string $jwtApiUrl,
		string $hashType,
		string $recurringUrl = 'https://auth.robokassa.ru/Merchant/Recurring'
	) {
		$this->http = $http;
		$this->sign = $sign;
		$this->merchantLogin = $login;
		$this->password1 = $password1;
		$this->isTest = $isTest;
		$this->paymentUrl = $paymentUrl;
		$this->paymentCurl = $paymentCurl;
		$this->jwtApiUrl = $jwtApiUrl;
		$this->hashType = $hashType;
		$this->recurringUrl = $recurringUrl;
	}

	/**
	 * Отправка платёжного запроса через CURL (Indexjson.aspx).
	 *
	 * @deprecated будет удалён в следующей major версии. Используйте sendJwt().
	 * @param array $params
	 * @return string
	 * @throws RobokassaException
	 */
	public function sendCurl(array $params): string {
		$params = $this->prepareCurlParams($params);
		$sigParams = $this->buildCurlSignature($params);
		$params['SignatureValue'] = $this->sign->createPaymentSignature(
			$sigParams,
			$this->merchantLogin,
			$this->password1,
			$this->hashType
		);
		$resp = $this->http->post($this->paymentCurl, http_build_query($params), array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		));
		$this->assertSuccessStatus($resp, 'Failed to send payment request.');
		$data = $this->decodeJsonResponse($resp->body);
		if (!empty($data['invoiceID'])) {
			return $this->paymentUrl . $data['invoiceID'];
		}
		throw new RobokassaException('Invoice ID not found in response.');
	}

	/**
	 * Создание счёта через JWT интерфейс.
	 *
	 * @param array $params
	 * @return string
	 * @throws RobokassaException
	 */
	public function sendJwt(array $params): string {
		$payload = $this->buildJwtPayload($params);
		list(, , $toSign) = $this->sign->encodeJwtParts(array('alg' => 'MD5', 'typ' => 'JWT'), $payload);
		$jwt = $toSign . '.' . $this->sign->jwtSignMd5($toSign, $this->merchantLogin, $this->password1);
		$resp = $this->http->post(
			$this->jwtApiUrl,
			$this->encodeJson($jwt),
			array('Content-Type' => 'application/json')
		);
		$this->assertSuccessStatus($resp, 'JWT request failed.');
		$data = $this->decodeJsonResponse($resp->body);
		if (!empty($data['url'])) {
			return $data['url'];
		}
		throw new RobokassaException('JWT response does not contain payment URL.');
	}

	/**
	 * Создание счёта для оплаты по сохранённой карте через JWT интерфейс.
	 *
	 * @param array $params
	 * @return string
	 * @throws RobokassaException
	 */
	public function sendSavedCard(array $params): string {
		return $this->sendJwt($this->prepareSavedCardParams($params));
	}

	/**
	 * Создание дочернего рекуррентного платежа.
	 *
	 * @param array $params
	 * @return string
	 * @throws RobokassaException
	 */
	public function sendRecurring(array $params): string {
		$params = $this->prepareRecurringParams($params);
		$sigParams = $this->buildRecurringSignature($params);
		$params['SignatureValue'] = $this->sign->createPaymentSignature(
			$sigParams,
			$this->merchantLogin,
			$this->password1,
			$this->hashType
		);
		$resp = $this->http->post($this->recurringUrl, http_build_query($params), array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		));
		$this->assertSuccessStatus($resp, 'Recurring payment request failed.');
		return $this->decodeRecurringResponse($resp->body);
	}

	/**
	 * Подготовка параметров для CURL-запроса.
	 *
	 * @param array $params
	 * @return array
	 * @throws RobokassaException
	 */
	private function prepareCurlParams(array $params): array {
		if (empty($params['OutSum']) || empty($params['Description'])) {
			throw new RobokassaException('Required parameters are missing: OutSum, Description');
		}
		$params['MerchantLogin'] = $this->merchantLogin;
		if (!empty($params['Receipt'])) {
			$encoded = urlencode($this->encodeJson($params['Receipt']));
			$params['Receipt'] = urlencode($encoded);
		}
		return $this->encodeShpParams($params);
	}

	/**
	 * Подготовка параметров дочернего рекуррентного платежа.
	 *
	 * @param array $params
	 * @return array
	 * @throws RobokassaException
	 */
	private function prepareRecurringParams(array $params): array {
		if ($this->isTest) {
			throw new RobokassaException('Recurring payments are not supported in test mode.');
		}
		foreach (array('OutSum', 'InvoiceID', 'PreviousInvoiceID') as $required) {
			if (!array_key_exists($required, $params)) {
				throw new RobokassaException('Required parameters: OutSum, InvoiceID, PreviousInvoiceID');
			}
		}
		foreach (array('Recurring', 'IncCurrLabel', 'ExpirationDate', 'IsTest') as $forbidden) {
			if (array_key_exists($forbidden, $params)) {
				throw new RobokassaException('Forbidden recurring parameter: ' . $forbidden);
			}
		}
		foreach ($params as $name => $value) {
			if (!in_array($name, array('OutSum', 'InvoiceID', 'PreviousInvoiceID', 'Description', 'Receipt'), true)
				&& !preg_match('~^Shp_~iu', $name)) {
				throw new RobokassaException('Unsupported recurring parameter: ' . $name);
			}
		}
		if (!$this->isPositiveInteger($params['InvoiceID'])) {
			throw new RobokassaException('Invalid recurring parameter InvoiceID: positive integer expected.');
		}
		if (!$this->isPositiveInteger($params['PreviousInvoiceID'])) {
			throw new RobokassaException('Invalid recurring parameter PreviousInvoiceID: positive integer expected.');
		}
		if (!$this->isPositiveAmount($params['OutSum'])) {
			throw new RobokassaException('Invalid recurring parameter OutSum: positive decimal expected.');
		}
		$params['MerchantLogin'] = $this->merchantLogin;
		if (!empty($params['Receipt'])) {
			$params['Receipt'] = urlencode($this->encodeJson($params['Receipt']));
		}
		return $this->encodeShpParams($params);
	}

	/**
	 * Подготовка параметров оплаты по сохранённой карте.
	 *
	 * @param array $params
	 * @return array
	 * @throws RobokassaException
	 */
	private function prepareSavedCardParams(array $params): array {
		$additional = $this->getSavedCardAdditionalParameters($params);
		$rootTokenExists = array_key_exists('Token', $params);
		$additionalTokenExists = array_key_exists('Token', $additional);

		if (!$rootTokenExists && !$additionalTokenExists) {
			throw new RobokassaException('Required saved card parameter: Token');
		}
		$rootToken = $rootTokenExists ? $this->normalizeSavedCardToken($params['Token']) : null;
		$additionalToken = $additionalTokenExists ? $this->normalizeSavedCardToken($additional['Token']) : null;
		if ($rootToken !== null && $additionalToken !== null && $rootToken !== $additionalToken) {
			throw new RobokassaException('Conflicting saved card Token values.');
		}

		$this->assertSavedCardExclusiveParameters($params, $additional);

		$additional['Token'] = $rootToken !== null ? $rootToken : $additionalToken;
		$params['AdditionalParameters'] = $additional;
		unset($params['Token']);

		return $params;
	}

	/**
	 * Нормализует Token сохранённой карты.
	 *
	 * @param mixed $token
	 * @return string
	 * @throws RobokassaException
	 */
	private function normalizeSavedCardToken($token): string {
		if (is_array($token) || is_object($token)) {
			throw new RobokassaException('Invalid saved card parameter Token: string expected.');
		}
		$token = trim((string)$token);
		if ($token === '') {
			throw new RobokassaException('Required saved card parameter: Token');
		}
		return $token;
	}

	/**
	 * Возвращает AdditionalParameters для оплаты по сохранённой карте.
	 *
	 * @param array $params
	 * @return array
	 * @throws RobokassaException
	 */
	private function getSavedCardAdditionalParameters(array $params): array {
		if (!array_key_exists('AdditionalParameters', $params)) {
			return array();
		}
		if (!is_array($params['AdditionalParameters'])) {
			throw new RobokassaException('AdditionalParameters must be an array.');
		}
		return $params['AdditionalParameters'];
	}

	/**
	 * Проверяет взаимоисключающие параметры Invoice API для оплаты по сохранённой карте.
	 *
	 * @param array $params
	 * @param array $additional
	 * @return void
	 * @throws RobokassaException
	 */
	private function assertSavedCardExclusiveParameters(array $params, array $additional): void {
		foreach (array('Recurring', 'StepByStep') as $name) {
			if (array_key_exists($name, $params)) {
				throw new RobokassaException('Forbidden saved card parameter: ' . $name);
			}
			if (array_key_exists($name, $additional)) {
				throw new RobokassaException('Forbidden saved card parameter: AdditionalParameters.' . $name);
			}
		}
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	private function isPositiveInteger($value): bool {
		if (!is_int($value) && !is_string($value)) {
			return false;
		}
		$value = (string)$value;
		return preg_match('~^\d+$~D', $value) === 1 && preg_match('~[1-9]~', $value) === 1;
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	private function isPositiveAmount($value): bool {
		if (!is_int($value) && !is_float($value) && !is_string($value)) {
			return false;
		}
		$value = (string)$value;
		return preg_match('~^\d+(?:\.\d+)?$~D', $value) === 1 && preg_match('~[1-9]~', $value) === 1;
	}

	/**
	 * Формирование массива для подписи.
	 *
	 * @param array $params
	 * @return array
	 */
	private function buildCurlSignature(array $params): array {
		$sig = array('OutSum' => $params['OutSum'], 'InvoiceID' => $params['InvoiceID'] ?? '');
		if (!empty($params['Receipt'])) {
			$sig['Receipt'] = urldecode($params['Receipt']);
		}
		return $this->appendShpParams($sig, $params);
	}

	/**
	 * Формирование массива для подписи рекуррентного платежа.
	 *
	 * @param array $params
	 * @return array
	 */
	private function buildRecurringSignature(array $params): array {
		$sig = array('OutSum' => $params['OutSum'], 'InvoiceID' => $params['InvoiceID']);
		if (!empty($params['Receipt'])) {
			$sig['Receipt'] = $params['Receipt'];
		}
		return $this->appendShpParams($sig, $params);
	}

	/**
	 * Подготовка payload для JWT.
	 *
	 * @param array $params
	 * @return array
	 * @throws RobokassaException
	 */
	private function buildJwtPayload(array $params): array {
		if (empty($params['OutSum']) || !isset($params['InvId'])) {
			throw new RobokassaException('Required parameters: OutSum, InvId');
		}
		$payload = $this->buildRequiredJwtPayload($params);
		return $this->appendOptionalJwtPayload($payload, $params);
	}

	/**
	 * Собирает обязательные поля JWT payload.
	 *
	 * @param array $params
	 * @return array
	 */
	private function buildRequiredJwtPayload(array $params): array {
		return array(
			'MerchantLogin' => $this->merchantLogin,
			'InvoiceType' => $params['InvoiceType'] ?? 'OneTime',
			'Culture' => $params['Culture'] ?? 'ru',
			'InvId' => (int)$params['InvId'],
			'OutSum' => (float)$params['OutSum'],
		);
	}

	/**
	 * Добавляет опциональные поля JWT payload.
	 *
	 * @param array $payload
	 * @param array $params
	 * @return array
	 */
	private function appendOptionalJwtPayload(array $payload, array $params): array {
		$optional = array(
			'Description',
			'MerchantComments',
			'InvoiceItems',
			'UserFields',
			'SuccessUrl2Data',
			'FailUrl2Data',
			'AdditionalParameters',
		);
		foreach ($optional as $key) {
			if (!empty($params[$key])) {
				$payload[$key] = $params[$key];
			}
		}
		return $payload;
	}

	/**
	 * Кодирует параметры Shp_* и добавляет тестовый режим.
	 *
	 * @param array $params
	 * @return array
	 */
	private function encodeShpParams(array $params): array {
		if ($this->isTest) {
			$params['IsTest'] = '1';
		}
		foreach ($params as $name => $value) {
			if (preg_match('~^Shp_~iu', $name)) {
				$params[$name] = urlencode($value);
			}
		}
		return $params;
	}

	/**
	 * Добавляет параметры Shp_* в массив подписи.
	 *
	 * @param array $sig
	 * @param array $params
	 * @return array
	 */
	private function appendShpParams(array $sig, array $params): array {
		foreach ($params as $name => $value) {
			if (preg_match('~^Shp_~iu', $name)) {
				$sig[$name] = $value;
			}
		}
		return $sig;
	}

	/**
	 * Проверяет успешный HTTP-статус.
	 *
	 * @param Response $response
	 * @param string $message
	 * @return void
	 * @throws RobokassaException
	 */
	private function assertSuccessStatus(Response $response, string $message): void {
		if ($response->status !== 200) {
			throw new RobokassaException($message . ' HTTP Status: ' . $response->status);
		}
	}

	/**
	 * Кодирует данные в JSON с проверкой ошибки.
	 *
	 * @param mixed $data
	 * @param int $flags
	 * @return string
	 * @throws RobokassaException
	 */
	private function encodeJson($data, int $flags = 0): string {
		$json = json_encode($data, $flags);
		if ($json === false) {
			throw new RobokassaException('Ошибка кодирования JSON: ' . json_last_error_msg());
		}
		return $json;
	}

	/**
	 * Разбирает JSON-ответ с проверкой пустого и невалидного тела.
	 *
	 * @param string $body
	 * @return array
	 * @throws RobokassaException
	 */
	private function decodeJsonResponse(string $body): array {
		if (trim($body) === '') {
			throw new RobokassaException('Пустой JSON-ответ');
		}
		$data = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RobokassaException('Некорректный JSON в ответе: ' . json_last_error_msg());
		}
		if (!is_array($data)) {
			throw new RobokassaException('JSON-ответ должен быть объектом или массивом');
		}
		return $data;
	}

	/**
	 * Проверяет текстовый ответ рекуррентного платежа.
	 *
	 * @param string $body
	 * @return string
	 * @throws RobokassaException
	 */
	private function decodeRecurringResponse(string $body): string {
		$body = trim($body);
		if ($body === '') {
			throw new RobokassaException('Empty recurring payment response.');
		}
		if (!preg_match('~^OK\+?\d+$~i', $body)) {
			throw new RobokassaException('Recurring payment response is not successful.');
		}
		return $body;
	}
}
