<?php
namespace Robokassa\Service;

use Robokassa\Client\HttpClientInterface;
use Robokassa\Client\Response;
use Robokassa\Exception\RobokassaException;
use Robokassa\Signature\SignatureService;

class StatusService {
	/** @var string */
	private $endpoint = 'https://services.robokassa.ru/InvoiceServiceWebApi/api/GetInvoiceInformationList';

	private HttpClientInterface $http;
	private SignatureService $sign;
	private string $merchantLogin;
	private string $password1;

	/**
	 * Создаёт сервис статусов счетов.
	 *
	 * @param HttpClientInterface $http
	 * @param string $merchantLogin
	 * @param string $password1
	 * @param SignatureService|null $sign
	 * @throws RobokassaException
	 */
	public function __construct($http, $merchantLogin, $password1, $sign = null) {
		if (!$http instanceof HttpClientInterface) {
			throw new RobokassaException('Param http must implement HttpClientInterface');
		}
		if ($sign !== null && !$sign instanceof SignatureService) {
			throw new RobokassaException('Param sign must be instance of SignatureService');
		}
		$this->http = $http;
		$this->merchantLogin = (string)$merchantLogin;
		$this->password1 = (string)$password1;
		$this->sign = $sign ?: new SignatureService('md5');
	}

	/**
	 * Получить список счетов/ссылок по фильтрам.
	 *
	 * Обязательные поля: CurrentPage, PageSize, InvoiceStatuses, DateFrom, DateTo, InvoiceTypes.
	 *
	 * @param array $filters
	 * @return array
	 * @throws RobokassaException
	 */
	public function getInvoiceInformationList(array $filters) {
		$this->assertRequiredFilters($filters);
		$filters = $this->normalizeFilters($filters);
		$jwt = $this->buildJwt($filters);
		$resp = $this->http->post($this->endpoint, $this->encodeJson($jwt), array(
			'Content-Type' => 'application/json',
		));
		$this->assertSuccessStatus($resp, 'Ошибка получения списка счетов.');
		return $this->decodeJsonResponse($resp->body);
	}

	/**
	 * Проверяет обязательные фильтры запроса.
	 *
	 * @param array $filters
	 * @return void
	 * @throws RobokassaException
	 */
	private function assertRequiredFilters(array $filters): void {
		$required = array('CurrentPage','PageSize','InvoiceStatuses','DateFrom','DateTo','InvoiceTypes');
		foreach ($required as $req) {
			if (!array_key_exists($req, $filters)) {
				throw new RobokassaException('Missing required field: ' . $req);
			}
		}
	}

	/**
	 * Нормализует фильтры статусов и типов счетов.
	 *
	 * @param array $filters
	 * @return array
	 */
	private function normalizeFilters(array $filters): array {
		if (isset($filters['InvoiceStatuses'])) {
			$filters['InvoiceStatuses'] = $this->normalizeList($filters['InvoiceStatuses']);
		}
		if (isset($filters['InvoiceTypes'])) {
			$filters['InvoiceTypes'] = $this->normalizeList($filters['InvoiceTypes']);
		}
		return $filters;
	}

	/**
	 * Нормализует список значений, если он передан массивом.
	 *
	 * @param mixed $list
	 * @return mixed
	 */
	private function normalizeList($list) {
		if (!is_array($list)) {
			return $list;
		}
		$normalized = array();
		foreach ($list as $value) {
			$normalized[] = $this->normalizeListValue($value);
		}
		return $normalized;
	}

	/**
	 * Нормализует одно значение фильтра.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function normalizeListValue($value) {
		$map = array(
			'paid' => 'Paid',
			'expired' => 'Expired',
			'notpaid' => 'Notpaid',
			'onetime' => 'OneTime',
			'reusable' => 'Reusable',
		);
		$key = strtolower((string)$value);
		return $map[$key] ?? $value;
	}

	/**
	 * Формирует JWT для запроса списка счетов.
	 *
	 * @param array $filters
	 * @return string
	 * @throws RobokassaException
	 */
	private function buildJwt(array $filters): string {
		$payload = array_merge(array('MerchantLogin' => $this->merchantLogin), $filters);
		list(, , $toSign) = $this->sign->encodeJwtParts(array('alg' => 'MD5', 'typ' => 'JWT'), $payload);
		$signature = $this->sign->jwtSignMd5($toSign, $this->merchantLogin, $this->password1);
		return $toSign . '.' . $signature;
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
	 * @return string
	 * @throws RobokassaException
	 */
	private function encodeJson($data): string {
		$json = json_encode($data);
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
