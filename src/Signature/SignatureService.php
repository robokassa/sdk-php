<?php
namespace Robokassa\Signature;

use Robokassa\Exception\RobokassaException;

class SignatureService {
	/** @var string */
	private $defaultAlgo;

	/** @var HashAlgorithmResolver */
	private $algorithmResolver;

	/**
	 * @param string $defaultAlgo
	 * @param HashAlgorithmResolver|null $algorithmResolver
	 * @throws RobokassaException
	 */
	public function __construct($defaultAlgo = 'md5', ?HashAlgorithmResolver $algorithmResolver = null) {
		$this->algorithmResolver = $algorithmResolver ?: new HashAlgorithmResolver($defaultAlgo);
		$this->defaultAlgo = $this->algorithmResolver->resolve($defaultAlgo);
	}

	/** Base64URL без паддинга */
	public function b64url($data) {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Схема “второго чека / статуса чека”:
	 * hash(base64(payload) . secret) -> HEX, затем base64url(HEX)
	 *
	 * @param string      $base64Payload
	 * @param string      $secret
	 * @param string|null $algo
	 * @return string
	 * @throws RobokassaException
	 */
	public function signFiscal($base64Payload, $secret, $algo = null) {
		$algo = $this->resolveAlgorithm($algo);
		$hashHex = hash($algo, $base64Payload . $secret, false);
		return $this->b64url($hashHex);
	}

	/**
	 * Подпись JWT (CreateInvoice / GetInvoiceInformationList):
	 * HMAC-MD5 (binary) -> base64url
	 *
	 * @param string $dataToSign
	 * @param string $merchantLogin
	 * @param string $password1
	 * @return string
	 */
	public function jwtSignMd5($dataToSign, $merchantLogin, $password1) {
		$raw = hash_hmac('md5', $dataToSign, $merchantLogin . ':' . $password1, true);
		return $this->b64url($raw);
	}

	/**
	 * Возвращает [encodedHeader, encodedPayload, dataToSign]
	 *
	 * @param array $header
	 * @param array $payload
	 * @return array{0:string,1:string,2:string}
	 * @throws RobokassaException
	 */
	public function encodeJwtParts(array $header, array $payload) {
		$encHeader = $this->b64url($this->encodeJson($header));
		$encPayload = $this->b64url($this->encodeJson($payload));
		return array($encHeader, $encPayload, $encHeader . '.' . $encPayload);
	}

	/**
	 * Подпись для платежного запроса (Indexjson.aspx).
	 *
	 * Формат строки для хэша:
	 *   login:OutSum:InvoiceID[:Receipt]:password1[:Shp_key=value ...]
	 *
	 * Где пары Shp_* добавляются как key=value и сортируются по ключу (lexicographically).
	 *
	 * @param array       $params     Должны содержать OutSum, InvoiceID, опционально Receipt и Shp_* поля
	 * @param string      $login
	 * @param string      $password1
	 * @param string|null $algo       md5|ripemd160|sha1|sha256|sha384|sha512
	 * @return string                 HEX-хеш
	 * @throws RobokassaException
	 */
	public function createPaymentSignature(array $params, $login, $password1, $algo = null) {
		$hashString = $this->buildPaymentHashString($params, $login, $password1);
		return hash($this->resolveAlgorithm($algo), $hashString);
	}

	/**
	 * Собирает строку для подписи платёжного запроса.
	 *
	 * @param array $params
	 * @param string $login
	 * @param string $password1
	 * @return string
	 */
	private function buildPaymentHashString(array $params, $login, $password1): string {
		$required = $this->buildPaymentRequiredParts($params, $login, $password1);
		$pairs = $this->collectShpPairs($params);
		$hashString = implode(':', $required);
		if (!empty($pairs)) {
			$hashString .= ':' . implode(':', $pairs);
		}
		return $hashString;
	}

	/**
	 * Собирает обязательные части строки подписи.
	 *
	 * @param array $params
	 * @param string $login
	 * @param string $password1
	 * @return array
	 */
	private function buildPaymentRequiredParts(array $params, $login, $password1): array {
		$required = array($login, $params['OutSum'], $params['InvoiceID']);
		if (!empty($params['Receipt'])) {
			$required[] = $params['Receipt'];
		}
		$required[] = $password1;
		return $required;
	}

	/**
	 * Собирает отсортированные пользовательские параметры Shp_*.
	 *
	 * @param array $params
	 * @return array
	 */
	private function collectShpPairs(array $params): array {
		$pairs = array();
		foreach ($params as $k => $v) {
			if (preg_match('~^Shp_~iu', $k)) {
				$pairs[] = $k . '=' . $v;
			}
		}
		sort($pairs);
		return $pairs;
	}

	/**
	 * Подпись для WebService OpStateExt.
	 *
	 * Формат:
	 *   hash(algo, "{login}:{invoiceID}:{password2}")
	 *
	 * @param string      $login
	 * @param string      $invoiceID
	 * @param string      $password2
	 * @param string|null $algo       md5|ripemd160|sha1|sha256|sha384|sha512
	 * @return string                 HEX-хеш
	 * @throws RobokassaException
	 */
	public function signOpState($login, $invoiceID, $password2, $algo = null) {
		$algo = $this->resolveAlgorithm($algo);
		return hash($algo, $login . ':' . $invoiceID . ':' . $password2);
	}

	/**
	 * Выбирает алгоритм подписи.
	 *
	 * @param string|null $algo
	 * @return string
	 * @throws RobokassaException
	 */
	private function resolveAlgorithm($algo = null): string {
		return $this->algorithmResolver->resolve($algo === null ? $this->defaultAlgo : $algo);
	}

	/**
	 * Кодирует данные в JSON для JWT.
	 *
	 * @param array $data
	 * @return string
	 * @throws RobokassaException
	 */
	private function encodeJson(array $data): string {
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new RobokassaException('Ошибка кодирования JSON: ' . json_last_error_msg());
		}
		return $json;
	}
}
