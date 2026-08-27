<?php
namespace Robokassa;

use Robokassa\Client\HttpClientInterface;
use Robokassa\Exception\RobokassaException;
use Robokassa\Signature\HashAlgorithmResolver;
use Robokassa\Signature\SignatureService;
use Robokassa\Service\PaymentService;
use Robokassa\Service\ReceiptService;
use Robokassa\Service\StatusService;
use Robokassa\Service\WebService;

class Robokassa {
	private HttpClientInterface $httpClient;

	/** @var SignatureService */
	private SignatureService $signer;

	private string $paymentUrl	  = 'https://auth.robokassa.ru/Merchant/Index/';
	private string $paymentCurl	  = 'https://auth.robokassa.ru/Merchant/Indexjson.aspx';
	private string $jwtApiUrl	  = 'https://services.robokassa.ru/InvoiceServiceWebApi/api/CreateInvoice';
	private string $recurringUrl  = 'https://auth.robokassa.ru/Merchant/Recurring';
	private string $webServiceUrl = 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx';

	private bool $is_test = false;

	protected string $password1;
	protected string $password2;
	private string $hashType = 'md5';
	private string $login;

	private PaymentService $paymentService;
	private ReceiptService $receiptService;
	private StatusService $statusService;
	private WebService $webService;

	/**
	 * @param array $params
	 * @param HttpClientInterface $httpClient	Реализация передаётся снаружи (фасад не знает про Guzzle)
	 * @param SignatureService|null $signer
	 * @throws RobokassaException
	 */
	public function __construct(array $params, HttpClientInterface $httpClient, ?SignatureService $signer = null) {
		$this->httpClient = $httpClient;

		$this->validateParams($params);
		$this->is_test = !empty($params['is_test']);
		$this->hashType = $this->resolveHashType($params);
		$this->login = (string)$params['login'];
		$this->password1 = $this->is_test ? (string)$params['test_password1'] : (string)$params['password1'];
		$this->password2 = $this->is_test ? (string)$params['test_password2'] : (string)$params['password2'];
		$this->signer = $signer ?? new SignatureService($this->hashType);
		$this->initServices();
	}

	/**
	 * Проверяет обязательные параметры клиента.
	 *
	 * @param array $params
	 * @return void
	 * @throws RobokassaException
	 */
	private function validateParams(array $params): void {
		if (empty($params['login'])) {
			throw new RobokassaException('Param login is not defined');
		}
		if (empty($params['password1'])) {
			throw new RobokassaException('Param password1 is not defined');
		}
		if (empty($params['password2'])) {
			throw new RobokassaException('Param password2 is not defined');
		}
		if (!empty($params['is_test'])) {
			$this->validateTestPasswords($params);
		}
	}

	/**
	 * Проверяет тестовые пароли.
	 *
	 * @param array $params
	 * @return void
	 * @throws RobokassaException
	 */
	private function validateTestPasswords(array $params): void {
		if (empty($params['test_password1'])) {
			throw new RobokassaException('Param test_password1 is not defined');
		}
		if (empty($params['test_password2'])) {
			throw new RobokassaException('Param test_password2 is not defined');
		}
	}

	/**
	 * Возвращает алгоритм подписи из настроек.
	 *
	 * @param array $params
	 * @return string
	 * @throws RobokassaException
	 */
	private function resolveHashType(array $params): string {
		$resolver = new HashAlgorithmResolver();
		return $resolver->resolve($params['hashType'] ?? $this->hashType);
	}

	/**
	 * Инициализирует сервисы SDK.
	 *
	 * @return void
	 */
	private function initServices(): void {
		$this->paymentService = $this->createPaymentService();
		$this->receiptService = $this->createReceiptService();
		$this->statusService = $this->createStatusService();
		$this->webService = $this->createWebService();
	}

	/**
	 * Создаёт сервис платежей.
	 */
	private function createPaymentService(): PaymentService {
		return new PaymentService(
			$this->httpClient,
			$this->signer,
			$this->login,
			$this->password1,
			$this->is_test,
			$this->paymentUrl,
			$this->paymentCurl,
			$this->jwtApiUrl,
			$this->hashType,
			$this->recurringUrl
		);
	}

	/**
	 * Создаёт сервис чеков.
	 */
	private function createReceiptService(): ReceiptService {
		return new ReceiptService($this->httpClient, $this->signer, $this->password1, $this->hashType);
	}

	/**
	 * Создаёт сервис статусов счетов.
	 */
	private function createStatusService(): StatusService {
		return new StatusService($this->httpClient, $this->login, $this->password1, $this->signer);
	}

	/**
	 * Создаёт сервис XML-интерфейсов.
	 */
	private function createWebService(): WebService {
		return new WebService(
			$this->httpClient,
			$this->signer,
			$this->login,
			$this->password2,
			$this->hashType,
			$this->webServiceUrl
		);
	}

	/**
	 * Сервис для работы с платежами
	 */
	public function payment(): PaymentService {
		return $this->paymentService;
	}

	/**
	 * Сервис для работы с чеками
	 */
	public function receipt(): ReceiptService {
		return $this->receiptService;
	}

	/**
	 * Сервис для работы со статусами счетов
	 */
	public function status(): StatusService {
		return $this->statusService;
	}

	/**
	 * Сервис для работы с XML интерфейсами
	 */
	public function webService(): WebService {
		return $this->webService;
	}
}
