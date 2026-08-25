<?php
namespace Robokassa\Service;

use Robokassa\Client\HttpClientInterface;
use Robokassa\Client\Response;
use Robokassa\Exception\RobokassaException;
use Robokassa\Signature\SignatureService;

class ReceiptService {
	private const SECOND_CHECK_URL = 'https://ws.roboxchange.com/RoboFiscal/Receipt/Attach';
	private const CHECK_STATUS_URL = 'https://ws.roboxchange.com/RoboFiscal/Receipt/Status';

	private HttpClientInterface $http;
	private SignatureService $sign;
	private string $password1;
	private string $hashType;

	public function __construct(HttpClientInterface $http, SignatureService $sign, string $password1, string $hashType) {
		$this->http = $http;
		$this->sign = $sign;
		$this->password1 = $password1;
		$this->hashType = $hashType;
	}

	/**
	 * Генерация строки второго чека
	 * @param array $payload
	 * @return string
	 * @throws RobokassaException
	 */
	public function getSecondCheckUrl(array $payload): string {
		$json = $this->encodeJson($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$base64Payload = $this->sign->b64url($json);
		$base64Signature = $this->sign->signFiscal($base64Payload, $this->password1, $this->hashType);
		return $base64Payload . '.' . $base64Signature;
	}

	/**
	 * Отправка второго чека в RoboFiscal
	 * @param array $payload
	 * @return string
	 * @throws RobokassaException
	 */
	public function sendSecondCheck(array $payload): string {
		$body = $this->getSecondCheckUrl($payload);
		$resp = $this->http->post(self::SECOND_CHECK_URL, $body, array('Content-Type' => 'application/json'));
		$this->assertSuccessStatus($resp, 'Ошибка отправки второго чека.');
		return $resp->body;
	}

	/**
	 * Получение статуса чека (RoboFiscal)
	 * @param array $payload
	 * @return array
	 * @throws RobokassaException
	 */
	public function getCheckStatus(array $payload): array {
		if (empty($payload['merchantId']) || empty($payload['id'])) {
			throw new RobokassaException('Не указаны обязательные параметры: merchantId и id (InvId).');
		}
		$json = $this->encodeJson($payload, JSON_UNESCAPED_UNICODE);
		$base64Payload = $this->sign->b64url($json);
		$base64Signature = $this->sign->signFiscal($base64Payload, $this->password1, $this->hashType);
		$body = $base64Payload . '.' . $base64Signature;
		$resp = $this->http->post(self::CHECK_STATUS_URL, $body, array('Content-Type' => 'application/json; charset=utf-8'));
		$this->assertSuccessStatus($resp, 'Ошибка получения статуса чека.');
		return $this->decodeJsonResponse($resp->body);
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
	 * @param array $data
	 * @param int $flags
	 * @return string
	 * @throws RobokassaException
	 */
	private function encodeJson(array $data, int $flags): string {
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
}
