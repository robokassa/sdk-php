<?php
namespace Robokassa\Service;

use Robokassa\Client\HttpClientInterface;
use Robokassa\Client\Response;
use Robokassa\Exception\RobokassaException;
use Robokassa\Signature\SignatureService;

/**
 * Сервис для работы с XML WebService Робокассы
 */
class WebService {
	private HttpClientInterface $http;
	private SignatureService $sign;
	private string $merchantLogin;
	private string $password2;
	private string $hashType;
	private string $url;

	/**
	 * @param HttpClientInterface $http
	 * @param SignatureService $sign
	 * @param string $merchantLogin
	 * @param string $password2
	 * @param string $hashType
	 * @param string $url
	 */
	public function __construct(HttpClientInterface $http, SignatureService $sign, string $merchantLogin, string $password2, string $hashType, string $url) {
		$this->http = $http;
		$this->sign = $sign;
		$this->merchantLogin = $merchantLogin;
		$this->password2 = $password2;
		$this->hashType = $hashType;
		$this->url = $url;
	}

	/**
	 * Получение списка доступных способов оплаты
	 *
	 * @param string $lang
	 * @return array
	 * @throws RobokassaException
	 */
	public function getPaymentMethods(string $lang = 'en'): array {
		if ($lang === '') {
			throw new RobokassaException('Param lang is not defined');
		}
		$query = http_build_query([
			'MerchantLogin' => $this->merchantLogin,
			'Language' => $lang,
		]);
		$resp = $this->http->get($this->buildUrl('GetPaymentMethods', $query));
		$this->assertSuccessStatus($resp);
		return $this->xmlToArray($resp->body);
	}

	/**
	 * Получение состояния оплаты счёта (OpStateExt)
	 *
	 * @param int $invoiceID
	 * @return array
	 * @throws RobokassaException
	 */
	public function opState(int $invoiceID): array {
		$query = http_build_query([
			'MerchantLogin' => $this->merchantLogin,
			'InvoiceID' => $invoiceID,
			'Signature' => $this->sign->signOpState(
				$this->merchantLogin,
				(string)$invoiceID,
				$this->password2,
				$this->hashType
			),
		]);
		$resp = $this->http->get($this->buildUrl('OpStateExt', $query));
		$this->assertSuccessStatus($resp);
		return $this->xmlToArray($resp->body);
	}

	private function buildUrl(string $segment, string $query): string {
		return $this->url . '/' . $segment . '?' . $query;
	}

	/**
	 * Проверяет успешный HTTP-статус XML-запроса.
	 *
	 * @param Response $response
	 * @return void
	 * @throws RobokassaException
	 */
	private function assertSuccessStatus(Response $response): void {
		if ($response->status !== 200) {
			throw new RobokassaException('Ошибка запроса: HTTP ' . $response->status);
		}
	}

	/**
	 * Преобразует XML-ответ в массив без PHP warning.
	 *
	 * @param string $xml
	 * @return array
	 * @throws RobokassaException
	 */
	private function xmlToArray(string $xml): array {
		if (trim($xml) === '') {
			throw new RobokassaException('Пустой XML-ответ');
		}
		$previous = libxml_use_internal_errors(true);
		$res = simplexml_load_string($xml);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($res === false) {
			throw new RobokassaException('Некорректный XML в ответе');
		}
		return $this->simpleXmlToArray($res);
	}

	/**
	 * Преобразует SimpleXML в массив без числовой конвертации строк.
	 *
	 * @param \SimpleXMLElement $xml
	 * @return array
	 * @throws RobokassaException
	 */
	private function simpleXmlToArray(\SimpleXMLElement $xml): array {
		$json = json_encode((array)$xml);
		if ($json === false) {
			throw new RobokassaException('Ошибка кодирования XML в JSON: ' . json_last_error_msg());
		}
		$data = json_decode($json, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
			throw new RobokassaException('Некорректный JSON после преобразования XML');
		}
		return $data;
	}
}
