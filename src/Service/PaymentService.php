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
	private string $holdConfirmUrl;
	private string $holdCancelUrl;

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
		string $recurringUrl = 'https://auth.robokassa.ru/Merchant/Recurring',
		string $holdConfirmUrl = 'https://auth.robokassa.ru/Merchant/Payment/Confirm',
		string $holdCancelUrl = 'https://auth.robokassa.ru/Merchant/Payment/Cancel'
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
		$this->holdConfirmUrl = $holdConfirmUrl;
		$this->holdCancelUrl = $holdCancelUrl;
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
		$params = $this->prepareJwtEnvironmentParams($params);
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
		if (($data['isSuccess'] ?? null) === false) {
			$message = isset($data['message']) && is_string($data['message'])
				? ': ' . $data['message']
				: '.';
			throw new RobokassaException('Invoice API request failed' . $message);
		}
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
	 * Создание счёта со сплитованием платежа через Invoice API.
	 *
	 * @param array $params
	 * @return string
	 * @throws RobokassaException
	 */
	public function sendSplit(array $params): string {
		return $this->sendJwt($this->prepareSplitParams($params));
	}

	/**
	 * Создание счёта с двухстадийной оплатой через Invoice API.
	 *
	 * @param array $params
	 * @return string
	 * @throws RobokassaException
	 */
	public function sendHold(array $params): string {
		return $this->sendJwt($this->prepareHoldParams($params));
	}

	/**
	 * Подтверждение списания удержанных средств.
	 *
	 * Возвращаемое значение означает, что запрос принят или отклонён. Итоговое
	 * состояние операции необходимо проверять через OpStateExt.
	 *
	 * @param int $invoiceID
	 * @param string $outSum
	 * @param array|null $receipt
	 * @return bool
	 * @throws RobokassaException
	 */
	public function confirmHold(int $invoiceID, string $outSum, ?array $receipt = null): bool {
		$this->assertHoldActionParams($invoiceID, $outSum);
		$params = array(
			'MerchantLogin' => $this->merchantLogin,
			'InvoiceID' => $invoiceID,
			'OutSum' => $outSum,
		);
		$encodedReceipt = null;
		if ($receipt !== null) {
			$encodedReceipt = urlencode($this->encodeJson($receipt));
			$params['Receipt'] = $encodedReceipt;
		}
		$params['SignatureValue'] = $this->sign->signHoldConfirm(
			$this->merchantLogin,
			$outSum,
			(string)$invoiceID,
			$this->password1,
			$encodedReceipt,
			$this->hashType
		);

		$resp = $this->http->post($this->holdConfirmUrl, http_build_query($params), array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		));
		$this->assertSuccessStatus($resp, 'Hold confirmation request failed.');

		return $this->decodeHoldActionResponse($resp->body);
	}

	/**
	 * Отмена холдирования.
	 *
	 * Возвращаемое значение означает, что запрос принят или отклонён. Итоговое
	 * состояние операции необходимо проверять через OpStateExt.
	 *
	 * @param int $invoiceID
	 * @param string $outSum
	 * @return bool
	 * @throws RobokassaException
	 */
	public function cancelHold(int $invoiceID, string $outSum): bool {
		$this->assertHoldActionParams($invoiceID, $outSum);
		$params = array(
			'MerchantLogin' => $this->merchantLogin,
			'InvoiceID' => $invoiceID,
			'OutSum' => $outSum,
			'SignatureValue' => $this->sign->signHoldCancel(
				$this->merchantLogin,
				(string)$invoiceID,
				$this->password1,
				$this->hashType
			),
		);

		$resp = $this->http->post($this->holdCancelUrl, http_build_query($params), array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		));
		$this->assertSuccessStatus($resp, 'Hold cancellation request failed.');

		return $this->decodeHoldActionResponse($resp->body);
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
	 * Согласует тестовый режим клиента с параметрами Invoice API.
	 *
	 * @param array $params
	 * @return array
	 * @throws RobokassaException
	 */
	private function prepareJwtEnvironmentParams(array $params): array {
		if (!$this->isTest) {
			return $params;
		}
		$additional = $this->getAdditionalParameters($params);
		if (array_key_exists('IsTest', $additional) && (string)$additional['IsTest'] !== '1') {
			throw new RobokassaException('Conflicting Invoice API test mode parameter: AdditionalParameters.IsTest');
		}
		$additional['IsTest'] = '1';
		$params['AdditionalParameters'] = $additional;

		return $params;
	}

	/**
	 * Подготовка параметров счёта со сплитованием.
	 *
	 * @param array $params
	 * @return array
	 * @throws RobokassaException
	 */
	private function prepareSplitParams(array $params): array {
		if ($this->isTest) {
			throw new RobokassaException('Split payments are not supported in test mode.');
		}
		if (!array_key_exists('Split', $params)) {
			throw new RobokassaException('Required split parameter: Split');
		}

		$additional = $this->getAdditionalParameters($params);
		if (array_key_exists('Split', $additional)) {
			throw new RobokassaException(
				'Split must be passed as a top-level SDK parameter, not as AdditionalParameters.Split.'
			);
		}
		if (array_key_exists('IsTest', $params) || array_key_exists('IsTest', $additional)) {
			throw new RobokassaException('Split payments are not compatible with IsTest.');
		}
		foreach ($additional as $name => $value) {
			if (!is_string($value)) {
				throw new RobokassaException(
					'Invalid split parameter AdditionalParameters.' . $name . ': string expected.'
				);
			}
		}

		$this->assertSplitMerchants($params['Split']);
		$additional['Split'] = $this->encodeJson(
			$params['Split'],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		$params['AdditionalParameters'] = $additional;
		unset($params['Split']);

		return $params;
	}

	/**
	 * Проверяет список магазинов-участников сплита.
	 *
	 * @param mixed $merchants
	 * @return void
	 * @throws RobokassaException
	 */
	private function assertSplitMerchants($merchants): void {
		if (!is_array($merchants) || empty($merchants)) {
			throw new RobokassaException('Split must be a non-empty array of merchants.');
		}
		if ($merchants !== array_values($merchants)) {
			throw new RobokassaException('Split must be a list of merchants.');
		}

		foreach ($merchants as $index => $merchant) {
			$path = 'Split[' . $index . ']';
			if (!is_array($merchant)) {
				throw new RobokassaException($path . ' must be an array.');
			}
			if (!isset($merchant['id']) || !is_string($merchant['id']) || trim($merchant['id']) === '') {
				throw new RobokassaException($path . '.id must be a non-empty string.');
			}
			if (!array_key_exists('amount', $merchant)) {
				throw new RobokassaException('Required split parameter: ' . $path . '.amount');
			}
			$this->assertNonNegativeJsonNumber($merchant['amount'], $path . '.amount');

			if (array_key_exists('InvoiceId', $merchant)
				&& (!is_int($merchant['InvoiceId']) || $merchant['InvoiceId'] < 0)) {
				throw new RobokassaException($path . '.InvoiceId must be a non-negative integer.');
			}
			if (array_key_exists('receipt', $merchant)) {
				$this->assertSplitReceipt($merchant['receipt'], $path . '.receipt');
			}
		}
	}

	/**
	 * Проверяет чек отдельного участника сплита.
	 *
	 * @param mixed $receipt
	 * @param string $path
	 * @return void
	 * @throws RobokassaException
	 */
	private function assertSplitReceipt($receipt, string $path): void {
		if (!is_array($receipt)) {
			throw new RobokassaException($path . ' must be an array.');
		}
		if (array_key_exists('sno', $receipt)
			&& (!is_string($receipt['sno'])
				|| !in_array($receipt['sno'], array(
					'osn',
					'usn_income',
					'usn_income_outcome',
					'envd',
					'esn',
					'patent',
				), true))) {
			throw new RobokassaException($path . '.sno has an unsupported value.');
		}
		if (!array_key_exists('items', $receipt)
			|| !is_array($receipt['items'])
			|| empty($receipt['items'])) {
			throw new RobokassaException($path . '.items must be a non-empty array.');
		}
		if ($receipt['items'] !== array_values($receipt['items'])) {
			throw new RobokassaException($path . '.items must be a list.');
		}

		foreach ($receipt['items'] as $index => $item) {
			$this->assertSplitReceiptItem($item, $path . '.items[' . $index . ']');
		}
	}

	/**
	 * Проверяет товарную позицию чека участника сплита.
	 *
	 * @param mixed $item
	 * @param string $path
	 * @return void
	 * @throws RobokassaException
	 */
	private function assertSplitReceiptItem($item, string $path): void {
		if (!is_array($item)) {
			throw new RobokassaException($path . ' must be an array.');
		}
		if (!isset($item['name']) || !is_string($item['name']) || trim($item['name']) === '') {
			throw new RobokassaException($path . '.name must be a non-empty string.');
		}
		if ($this->stringLength($item['name']) > 128) {
			throw new RobokassaException($path . '.name must not exceed 128 characters.');
		}
		if (!array_key_exists('quantity', $item)) {
			throw new RobokassaException('Required split parameter: ' . $path . '.quantity');
		}
		$this->assertSplitDecimal($item['quantity'], $path . '.quantity', 5, 3, false);
		if (!array_key_exists('sum', $item)) {
			throw new RobokassaException('Required split parameter: ' . $path . '.sum');
		}
		$this->assertSplitDecimal($item['sum'], $path . '.sum', 8, 2, true);
		if (!isset($item['tax']) || !is_string($item['tax'])
			|| !in_array($item['tax'], array('none', 'vat0', 'vat10', 'vat110', 'vat20', 'vat120'), true)) {
			throw new RobokassaException($path . '.tax has an unsupported value.');
		}

		$this->assertOptionalSplitEnum($item, 'payment_method', array(
			'full_prepayment',
			'prepayment',
			'advance',
			'full_payment',
			'partial_payment',
			'credit',
			'credit_payment',
		), $path);
		$this->assertOptionalSplitEnum($item, 'payment_object', array(
			'commodity',
			'excise',
			'job',
			'service',
			'gambling_bet',
			'gambling_prize',
			'lottery',
			'lottery_prize',
			'intellectual_activity',
			'payment',
			'agent_commission',
			'composite',
			'another',
			'property_right',
			'non-operating_gain',
			'insurance_premium',
			'sales_tax',
			'resort_fee',
		), $path);

		if (array_key_exists('nomenclature_code', $item) && !is_string($item['nomenclature_code'])) {
			throw new RobokassaException($path . '.nomenclature_code must be a string.');
		}
	}

	/**
	 * Проверяет необязательное строковое значение из фиксированного набора.
	 *
	 * @param array $data
	 * @param string $name
	 * @param array $allowed
	 * @param string $path
	 * @return void
	 * @throws RobokassaException
	 */
	private function assertOptionalSplitEnum(array $data, string $name, array $allowed, string $path): void {
		if (!array_key_exists($name, $data)) {
			return;
		}
		if (!is_string($data[$name]) || !in_array($data[$name], $allowed, true)) {
			throw new RobokassaException($path . '.' . $name . ' has an unsupported value.');
		}
	}

	/**
	 * @param mixed $value
	 * @param string $path
	 * @return void
	 * @throws RobokassaException
	 */
	private function assertNonNegativeJsonNumber($value, string $path): void {
		if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0) {
			throw new RobokassaException($path . ' must be a non-negative number.');
		}
	}

	/**
	 * @param mixed $value
	 * @param string $path
	 * @param int $maxIntegerDigits
	 * @param int $maxFractionDigits
	 * @param bool $allowZero
	 * @return void
	 * @throws RobokassaException
	 */
	private function assertSplitDecimal(
		$value,
		string $path,
		int $maxIntegerDigits,
		int $maxFractionDigits,
		bool $allowZero
	): void {
		$isNumber = is_int($value) || is_float($value);
		$isFinite = $isNumber && is_finite((float)$value);
		$isAllowedValue = $isFinite && ($allowZero ? $value >= 0 : $value > 0);
		$encoded = $isFinite ? json_encode($value, JSON_PRESERVE_ZERO_FRACTION) : false;
		$pattern = '~^\d{1,' . $maxIntegerDigits . '}(?:\.\d{1,' . $maxFractionDigits . '})?$~D';

		if (!$isFinite || !$isAllowedValue || !is_string($encoded) || preg_match($pattern, $encoded) !== 1) {
			$constraint = $allowZero ? 'non-negative' : 'positive';
			throw new RobokassaException(
				$path . ' must be a ' . $constraint . ' decimal with up to '
				. $maxIntegerDigits . ' integer and ' . $maxFractionDigits . ' fractional digits.'
			);
		}
	}

	/**
	 * Возвращает длину UTF-8 строки в символах без зависимости от mbstring.
	 *
	 * @param string $value
	 * @return int
	 * @throws RobokassaException
	 */
	private function stringLength(string $value): int {
		$count = preg_match_all('~.~us', $value, $matches);
		if ($count === false) {
			throw new RobokassaException('Invalid UTF-8 in split receipt item name.');
		}
		return $count;
	}

	/**
	 * Подготовка параметров создания холда через Invoice API.
	 *
	 * @param array $params
	 * @return array
	 * @throws RobokassaException
	 */
	private function prepareHoldParams(array $params): array {
		foreach (array('InvId', 'OutSum') as $required) {
			if (!array_key_exists($required, $params)) {
				throw new RobokassaException('Required hold parameters: InvId, OutSum');
			}
		}
		if (!$this->isPositiveInteger($params['InvId'])) {
			throw new RobokassaException('Invalid hold parameter InvId: positive integer expected.');
		}
		if (!$this->isPositiveAmount($params['OutSum'])) {
			throw new RobokassaException('Invalid hold parameter OutSum: positive decimal expected.');
		}
		if (isset($params['InvoiceType']) && $params['InvoiceType'] !== 'OneTime') {
			throw new RobokassaException('Hold payments support only InvoiceType OneTime.');
		}
		if (array_key_exists('StepByStep', $params)) {
			throw new RobokassaException('Hold parameter StepByStep must be passed inside AdditionalParameters.');
		}

		$additional = $this->getAdditionalParameters($params);
		foreach ($additional as $name => $value) {
			if (!is_string($value)) {
				throw new RobokassaException(
					'Invalid hold parameter AdditionalParameters.' . $name . ': string expected.'
				);
			}
		}
		foreach (array('Recurring', 'Token') as $name) {
			if (array_key_exists($name, $params)) {
				throw new RobokassaException('Forbidden hold parameter: ' . $name);
			}
			if (array_key_exists($name, $additional)) {
				throw new RobokassaException('Forbidden hold parameter: AdditionalParameters.' . $name);
			}
		}
		if (array_key_exists('StepByStep', $additional) && $additional['StepByStep'] !== 'true') {
			throw new RobokassaException('Conflicting hold StepByStep value.');
		}

		$additional['StepByStep'] = 'true';
		$params['InvoiceType'] = 'OneTime';
		$params['AdditionalParameters'] = $additional;

		return $params;
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
		$additional = $this->getAdditionalParameters($params);
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
	private function getAdditionalParameters(array $params): array {
		if (!array_key_exists('AdditionalParameters', $params)) {
			return array();
		}
		if (!is_array($params['AdditionalParameters'])) {
			throw new RobokassaException('AdditionalParameters must be an array.');
		}
		return $params['AdditionalParameters'];
	}

	/**
	 * Проверяет параметры подтверждения и отмены холда.
	 *
	 * @param int $invoiceID
	 * @param string $outSum
	 * @return void
	 * @throws RobokassaException
	 */
	private function assertHoldActionParams(int $invoiceID, string $outSum): void {
		if ($this->isTest) {
			throw new RobokassaException('Hold confirmation and cancellation are not supported in test mode.');
		}
		if ($invoiceID <= 0) {
			throw new RobokassaException('Invalid hold parameter InvoiceID: positive integer expected.');
		}
		if (!$this->isPositiveAmount($outSum)) {
			throw new RobokassaException('Invalid hold parameter OutSum: positive decimal expected.');
		}
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
			'ExpirationDate',
			'Description',
			'FiscalParentOpId',
			'MerchantComments',
			'InvoiceItems',
			'UserFields',
			'SuccessUrl2Data',
			'FailUrl2Data',
			'Aliases',
			'Payments',
			'CustomUserProperty',
			'IsWithoutFreeSale',
			'Sno',
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

	/**
	 * Разбирает JSON-строку ответа Confirm/Cancel.
	 *
	 * @param string $body
	 * @return bool
	 * @throws RobokassaException
	 */
	private function decodeHoldActionResponse(string $body): bool {
		if (trim($body) === '') {
			throw new RobokassaException('Empty hold action response.');
		}
		$data = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RobokassaException('Invalid JSON in hold action response: ' . json_last_error_msg());
		}
		if ($data === 'success: true') {
			return true;
		}
		if ($data === 'success: false') {
			return false;
		}

		throw new RobokassaException('Unexpected hold action response.');
	}
}
