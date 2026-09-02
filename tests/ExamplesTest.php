<?php
namespace Robokassa\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Robokassa\Client\HttpClient;
use Robokassa\Client\Response;
use Robokassa\Exception\RobokassaException;
use Robokassa\Robokassa;
use Robokassa\Service\PaymentService;
use Robokassa\Service\ReceiptService;
use Robokassa\Service\StatusService;
use Robokassa\Service\WebService;
use Robokassa\Signature\SignatureService;

/**
 * Тесты текущего поведения SDK.
 */
class ExamplesTest extends TestCase {
	private DummyClient $http;

	protected function setUp(): void {
		$this->http = new DummyClient();
	}

	public function testCreateMainClient(): void {
		$this->assertInstanceOf(Robokassa::class, $this->createRobo());
	}

	public function testFacadeReturnsAllServices(): void {
		$robo = $this->createRobo();

		$this->assertInstanceOf(PaymentService::class, $robo->payment());
		$this->assertInstanceOf(ReceiptService::class, $robo->receipt());
		$this->assertInstanceOf(StatusService::class, $robo->status());
		$this->assertInstanceOf(WebService::class, $robo->webService());
	}

	public function testAutoloadsPublicClassesSeparately(): void {
		$this->assertTrue(class_exists('Robokassa\Client\HttpClient'));
		$this->assertTrue(interface_exists('Robokassa\Client\HttpClientInterface'));
		$this->assertTrue(class_exists('Robokassa\Client\Response'));
		$this->assertTrue(class_exists('Robokassa\Exception\RobokassaException'));
		$this->assertTrue(class_exists('Robokassa\Robokassa'));
		$this->assertTrue(class_exists('Robokassa\Service\PaymentService'));
		$this->assertTrue(class_exists('Robokassa\Service\ReceiptService'));
		$this->assertTrue(class_exists('Robokassa\Service\StatusService'));
		$this->assertTrue(class_exists('Robokassa\Service\WebService'));
		$this->assertTrue(class_exists('Robokassa\Signature\HashAlgorithmResolver'));
		$this->assertTrue(class_exists('Robokassa\Signature\SignatureService'));
	}

	/**
	 * @dataProvider signatureAlgorithmProvider
	 */
	public function testSignatureAlgorithms(string $algorithm): void {
		$sign = new SignatureService($algorithm);
		$params = array('OutSum' => '10', 'InvoiceID' => '20');

		$this->assertSame(
			hash($algorithm, 'login:10:20:p1'),
			$sign->createPaymentSignature($params, 'login', 'p1')
		);
	}

	public function signatureAlgorithmProvider(): array {
		return array(
			array('md5'),
			array('ripemd160'),
			array('sha1'),
			array('sha256'),
			array('sha384'),
			array('sha512'),
		);
	}

	public function testUnknownSignatureAlgorithmThrows(): void {
		$sign = new SignatureService('md5');

		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Неизвестный алгоритм подписи');

		$sign->createPaymentSignature(array('OutSum' => 1, 'InvoiceID' => 1), 'login', 'p1', 'crc32');
	}

	public function testUnknownFacadeHashTypeThrows(): void {
		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Неизвестный алгоритм подписи');

		$this->createRobo(null, array('hashType' => 'crc32'));
	}

	public function testGetPaymentMethods(): void {
		$this->http->queueResponse(new Response('<Result><Method>Card</Method></Result>', 200));

		$res = $this->createRobo()->webService()->getPaymentMethods('ru');

		$this->assertSame('Card', $res['Method']);
	}

	public function testOpStateKeepsStringIdentifiersFromXml(): void {
		$this->http->queueResponse(new Response('<Response><InvoiceID>00123</InvoiceID></Response>', 200));

		$res = $this->createRobo()->webService()->opState(123);

		$this->assertSame('00123', $res['InvoiceID']);
	}

	public function testSendCurlKeepsCompatibilityAndExactRequest(): void {
		$this->http->queueResponse(new Response('{"invoiceID":10}', 200));

		$url = $this->createRobo()->payment()->sendCurl(array(
			'OutSum' => 5,
			'InvoiceID' => 9,
			'Description' => 'test',
			'Shp_order' => 'abc 1',
		));

		$this->assertSame('https://auth.robokassa.ru/Merchant/Index/10', $url);
		$this->assertSame('https://auth.robokassa.ru/Merchant/Indexjson.aspx', $this->http->lastUrl);
		$this->assertSame(array('Content-Type' => 'application/x-www-form-urlencoded'), $this->http->lastHeaders);
		$this->assertSame($this->expectedCurlBody(), $this->http->lastBody);
	}

	public function testSendCurlPassesRecurringAsPaymentParameter(): void {
		$this->http->queueResponse(new Response('{"invoiceID":10}', 200));

		$this->createRobo()->payment()->sendCurl(array(
			'OutSum' => 5,
			'InvoiceID' => 154,
			'Description' => 'Subscription parent payment',
			'Recurring' => 'true',
		));

		$this->assertSame(
			'OutSum=5&InvoiceID=154&Description=Subscription+parent+payment&Recurring=true'
				. '&MerchantLogin=login&SignatureValue=ea9bd729a4456cfbfb91294b8e2781d6',
			$this->http->lastBody
		);
	}

	public function testSendCurlIsDeprecated(): void {
		$method = new \ReflectionMethod(PaymentService::class, 'sendCurl');

		$this->assertStringContainsString('@deprecated', (string)$method->getDocComment());
		$this->assertStringContainsString('sendJwt()', (string)$method->getDocComment());
	}

	public function testSendJwtBuildsCurrentJwtAndHeaders(): void {
		$this->http->queueResponse(new Response('{"url":"https://pay"}', 200));

		$url = $this->createRobo()->payment()->sendJwt(array('InvId' => 1, 'OutSum' => 1, 'Description' => 'test'));

		$this->assertSame('https://pay', $url);
		$this->assertSame('https://services.robokassa.ru/InvoiceServiceWebApi/api/CreateInvoice', $this->http->lastUrl);
		$this->assertSame(array('Content-Type' => 'application/json'), $this->http->lastHeaders);
		$this->assertSame($this->expectedJwtBody(), $this->http->lastBody);
	}

	public function testSendJwtPassesRecurringInAdditionalParameters(): void {
		$this->http->queueResponse(new Response('{"url":"https://pay"}', 200));

		$this->createRobo()->payment()->sendJwt(array(
			'InvId' => 200001,
			'OutSum' => 100,
			'Description' => 'Subscription parent payment',
			'AdditionalParameters' => array(
				'Recurring' => 'true',
			),
		));

		$payload = $this->decodeJwtPayloadFromLastBody();

		$this->assertSame(array('Recurring' => 'true'), $payload['AdditionalParameters']);
		$this->assertArrayNotHasKey('Recurring', $payload);
	}

	public function testSendSavedCardPassesTokenInAdditionalParameters(): void {
		$this->http->queueResponse(new Response('{"url":"https://pay"}', 200));

		$url = $this->createRobo()->payment()->sendSavedCard(array(
			'InvId' => 300001,
			'OutSum' => 100,
			'Description' => 'Saved card payment',
			'Token' => 'E1253728-48A9-488D-A045-9954C442AF5C-qNavrXC6Y4',
			'AdditionalParameters' => array(
				'Email' => 'customer@example.com',
				'ResultURL2' => 'https://example.test/result',
			),
		));

		$payload = $this->decodeJwtPayloadFromLastBody();

		$this->assertSame('https://pay', $url);
		$this->assertSame('https://services.robokassa.ru/InvoiceServiceWebApi/api/CreateInvoice', $this->http->lastUrl);
		$this->assertSame(array('Content-Type' => 'application/json'), $this->http->lastHeaders);
		$this->assertArrayNotHasKey('Token', $payload);
		$this->assertSame(array(
			'Email' => 'customer@example.com',
			'ResultURL2' => 'https://example.test/result',
			'Token' => 'E1253728-48A9-488D-A045-9954C442AF5C-qNavrXC6Y4',
		), $payload['AdditionalParameters']);
	}

	public function testSendSavedCardAcceptsTokenFromAdditionalParameters(): void {
		$this->http->queueResponse(new Response('{"url":"https://pay"}', 200));

		$this->createRobo()->payment()->sendSavedCard(array(
			'InvId' => 300001,
			'OutSum' => 100,
			'AdditionalParameters' => array(
				'Token' => 'saved-card-token',
			),
		));

		$payload = $this->decodeJwtPayloadFromLastBody();

		$this->assertSame(array('Token' => 'saved-card-token'), $payload['AdditionalParameters']);
		$this->assertArrayNotHasKey('Token', $payload);
	}

	public function testSendSavedCardRequiresToken(): void {
		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Required saved card parameter: Token');

		$this->createRobo()->payment()->sendSavedCard(array(
			'InvId' => 300001,
			'OutSum' => 100,
		));
	}

	public function testSendSavedCardRejectsInvalidTokenType(): void {
		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Invalid saved card parameter Token: string expected.');

		$this->createRobo()->payment()->sendSavedCard(array(
			'InvId' => 300001,
			'OutSum' => 100,
			'Token' => array('saved-card-token'),
		));
	}

	public function testSendSavedCardRejectsInvalidAdditionalParameters(): void {
		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('AdditionalParameters must be an array.');

		$this->createRobo()->payment()->sendSavedCard(array(
			'InvId' => 300001,
			'OutSum' => 100,
			'Token' => 'saved-card-token',
			'AdditionalParameters' => 'Email=customer@example.com',
		));
	}

	public function testSendSavedCardRejectsConflictingTokenValues(): void {
		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Conflicting saved card Token values.');

		$this->createRobo()->payment()->sendSavedCard(array(
			'InvId' => 300001,
			'OutSum' => 100,
			'Token' => 'root-token',
			'AdditionalParameters' => array(
				'Token' => 'additional-token',
			),
		));
	}

	/**
	 * @dataProvider savedCardForbiddenParameterProvider
	 */
	public function testSendSavedCardRejectsForbiddenParameters(array $params, string $message): void {
		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage($message);

		$this->createRobo()->payment()->sendSavedCard($params);
	}

	public function savedCardForbiddenParameterProvider(): array {
		return array(
			array(
				array('InvId' => 300001, 'OutSum' => 100, 'Token' => 'saved-card-token', 'Recurring' => 'true'),
				'Forbidden saved card parameter: Recurring',
			),
			array(
				array('InvId' => 300001, 'OutSum' => 100, 'Token' => 'saved-card-token', 'StepByStep' => 'true'),
				'Forbidden saved card parameter: StepByStep',
			),
			array(
				array(
					'InvId' => 300001,
					'OutSum' => 100,
					'Token' => 'saved-card-token',
					'AdditionalParameters' => array('Recurring' => 'true'),
				),
				'Forbidden saved card parameter: AdditionalParameters.Recurring',
			),
			array(
				array(
					'InvId' => 300001,
					'OutSum' => 100,
					'Token' => 'saved-card-token',
					'AdditionalParameters' => array('StepByStep' => 'true'),
				),
				'Forbidden saved card parameter: AdditionalParameters.StepByStep',
			),
		);
	}

	public function testSendRecurringBuildsCurrentRequestAndReturnsOk(): void {
		$this->http->queueResponse(new Response('OK200002', 200));
		$receipt = array(
			'items' => array(array(
				'name' => 'Subscription',
				'quantity' => 1,
				'sum' => 100,
				'payment_method' => 'full_payment',
				'payment_object' => 'service',
				'tax' => 'none',
			)),
		);

		$result = $this->createRobo()->payment()->sendRecurring(array(
			'OutSum' => '100.00',
			'InvoiceID' => 200002,
			'PreviousInvoiceID' => 200001,
			'Description' => 'Recurring payment',
			'Receipt' => $receipt,
			'Shp_order' => 'abc 1',
		));

		$this->assertSame('OK200002', $result);
		$this->assertSame('https://auth.robokassa.ru/Merchant/Recurring', $this->http->lastUrl);
		$this->assertSame(array('Content-Type' => 'application/x-www-form-urlencoded'), $this->http->lastHeaders);
		$this->assertSame($this->expectedRecurringBody(), $this->http->lastBody);
		$this->assertStringNotContainsString('Receipt=%25257B', $this->http->lastBody);
		$this->assertStringNotContainsString(
			hash('md5', 'login:100.00:200002:200001:p1:Shp_order=abc+1'),
			$this->http->lastBody
		);
	}

	public function testSendRecurringRejectsTestMode(): void {
		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Recurring payments are not supported in test mode.');

		$this->createRobo(null, array(
			'is_test' => true,
			'test_password1' => 'tp1',
			'test_password2' => 'tp2',
		))->payment()->sendRecurring(array(
			'OutSum' => '100.00',
			'InvoiceID' => 200002,
			'PreviousInvoiceID' => 200001,
		));
	}

	public function testSendRecurringRequiresContractParameters(): void {
		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Required parameters: OutSum, InvoiceID, PreviousInvoiceID');

		$this->createRobo()->payment()->sendRecurring(array(
			'OutSum' => '100.00',
			'InvoiceID' => 200002,
		));
	}

	public function testSendRecurringRejectsForbiddenParameters(): void {
		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Forbidden recurring parameter: Recurring');

		$this->createRobo()->payment()->sendRecurring(array(
			'OutSum' => '100.00',
			'InvoiceID' => 200002,
			'PreviousInvoiceID' => 200001,
			'Recurring' => 'true',
		));
	}

	/**
	 * @dataProvider invalidRecurringIdentifierProvider
	 * @param mixed $value
	 */
	public function testSendRecurringRejectsInvalidIdentifiers(string $name, $value): void {
		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Invalid recurring parameter ' . $name . ': positive integer expected.');

		$params = array(
			'OutSum' => '100.00',
			'InvoiceID' => 200002,
			'PreviousInvoiceID' => 200001,
		);
		$params[$name] = $value;

		$this->createRobo()->payment()->sendRecurring($params);
	}

	public function invalidRecurringIdentifierProvider(): array {
		return array(
			array('InvoiceID', -1),
			array('InvoiceID', 1.5),
			array('InvoiceID', '1.5'),
			array('PreviousInvoiceID', -1),
			array('PreviousInvoiceID', 1.5),
			array('PreviousInvoiceID', '1.5'),
		);
	}

	/**
	 * @dataProvider invalidRecurringOutSumProvider
	 * @param mixed $value
	 */
	public function testSendRecurringRejectsInvalidOutSum($value): void {
		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Invalid recurring parameter OutSum: positive decimal expected.');

		$this->createRobo()->payment()->sendRecurring(array(
			'OutSum' => $value,
			'InvoiceID' => 200002,
			'PreviousInvoiceID' => 200001,
		));
	}

	public function invalidRecurringOutSumProvider(): array {
		return array(
			array(-1),
			array(0),
			array('1,00'),
			array('1e2'),
			array('invalid'),
		);
	}

	public function testSendRecurringRejectsUnsupportedParameter(): void {
		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Unsupported recurring parameter: Email');

		$this->createRobo()->payment()->sendRecurring(array(
			'OutSum' => '100.00',
			'InvoiceID' => 200002,
			'PreviousInvoiceID' => 200001,
			'Email' => 'customer@example.com',
		));
	}

	public function testSendRecurringRejectsNonOkResponse(): void {
		$this->http->queueResponse(new Response('Recurring error', 200));

		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Recurring payment response is not successful.');

		$this->createRobo()->payment()->sendRecurring(array(
			'OutSum' => '100.00',
			'InvoiceID' => 200002,
			'PreviousInvoiceID' => 200001,
		));
	}

	public function testGetCheckStatus(): void {
		$this->http->queueResponse(new Response('{"state":1}', 200));

		$res = $this->createRobo()->receipt()->getCheckStatus(array('merchantId' => 'm', 'id' => '1'));

		$this->assertSame(1, $res['state']);
		$this->assertSame(array('Content-Type' => 'application/json; charset=utf-8'), $this->http->lastHeaders);
	}

	public function testSendSecondCheck(): void {
		$this->http->queueResponse(new Response('ok', 200));

		$res = $this->createRobo()->receipt()->sendSecondCheck(array('a' => 'b'));

		$this->assertSame('ok', $res);
		$this->assertSame(array('Content-Type' => 'application/json'), $this->http->lastHeaders);
	}

	public function testFacadeStatusUsesPassedSignatureService(): void {
		$sign = new FixedSignatureService('md5');
		$this->http->queueResponse(new Response('{"items":[]}', 200));

		$res = $this->createRobo($sign)->status()->getInvoiceInformationList($this->statusFilters());

		$this->assertSame(array('items' => array()), $res);
		$this->assertSame(1, $sign->jwtSignCalls);
		$this->assertStringContainsString('fixed-signature', $this->http->lastBody);
	}

	public function testDirectStatusServiceCreationStillWorks(): void {
		$this->http->queueResponse(new Response('{"items":[]}', 200));
		$status = new StatusService($this->http, 'login', 'p1');

		$res = $status->getInvoiceInformationList($this->statusFilters());

		$this->assertSame(array('items' => array()), $res);
	}

	public function testStatusServicePublicContractKeepsOldNativeTypes(): void {
		$constructor = new \ReflectionMethod(StatusService::class, '__construct');
		$params = $constructor->getParameters();
		$method = new \ReflectionMethod(StatusService::class, 'getInvoiceInformationList');

		$this->assertFalse($params[0]->hasType());
		$this->assertFalse($params[1]->hasType());
		$this->assertFalse($params[2]->hasType());
		$this->assertNull($method->getReturnType());
	}

	public function testBadHttpStatusThrowsBeforeJsonParsing(): void {
		$this->http->queueResponse(new Response('secret-signature-body', 500));

		try {
			$this->createRobo()->payment()->sendJwt(array('InvId' => 1, 'OutSum' => 1));
			$this->fail('Ожидалось исключение RobokassaException');
		} catch (RobokassaException $e) {
			$this->assertStringContainsString('HTTP Status: 500', $e->getMessage());
			$this->assertStringNotContainsString('secret-signature-body', $e->getMessage());
		}
	}

	public function testHttpClientNetworkErrorUsesRobokassaException(): void {
		$mock = new MockHandler(array(
			new ConnectException('secret-signature-network-error', new Request('GET', 'https://example.test')),
		));
		$client = new HttpClient(new GuzzleClient(array('handler' => HandlerStack::create($mock))));

		try {
			$client->get('https://example.test');
			$this->fail('Ожидалось исключение RobokassaException');
		} catch (RobokassaException $e) {
			$this->assertSame('Сетевая ошибка HTTP GET', $e->getMessage());
			$this->assertNull($e->getPrevious());
			$this->assertExceptionChainDoesNotContain($e, array(
				'secret-signature-network-error',
				'signature',
				'https://example.test',
			));
		}
	}

	public function testHttpClientBadStatusUsesRobokassaException(): void {
		$mock = new MockHandler(array(
			new \GuzzleHttp\Psr7\Response(500, array(), 'secret-signature-body'),
		));
		$client = new HttpClient(new GuzzleClient(array('handler' => HandlerStack::create($mock))));

		try {
			$client->post('https://example.test', 'body');
			$this->fail('Ожидалось исключение RobokassaException');
		} catch (RobokassaException $e) {
			$this->assertStringContainsString('HTTP Status: 500', $e->getMessage());
			$this->assertNull($e->getPrevious());
			$this->assertExceptionChainDoesNotContain($e, array(
				'secret-signature-body',
				'signature',
				'https://example.test',
			));
		}
	}

	public function testInvalidJsonThrowsRobokassaException(): void {
		$this->http->queueResponse(new Response('{bad', 200));

		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Некорректный JSON');

		$this->createRobo()->payment()->sendJwt(array('InvId' => 1, 'OutSum' => 1));
	}

	public function testInvalidXmlThrowsRobokassaException(): void {
		$this->http->queueResponse(new Response('<Result>', 200));

		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Некорректный XML');

		$this->createRobo()->webService()->getPaymentMethods('ru');
	}

	public function testEmptyResponseThrowsRobokassaException(): void {
		$this->http->queueResponse(new Response('', 200));

		$this->expectException(RobokassaException::class);
		$this->expectExceptionMessage('Пустой JSON-ответ');

		$this->createRobo()->payment()->sendJwt(array('InvId' => 1, 'OutSum' => 1));
	}

	private function createRobo(?SignatureService $signer = null, array $overrides = array()): Robokassa {
		return new Robokassa(array_merge(array(
			'login' => 'login',
			'password1' => 'p1',
			'password2' => 'p2',
			'hashType' => 'md5',
		), $overrides), $this->http, $signer);
	}

	private function expectedCurlBody(): string {
		return 'OutSum=5&InvoiceID=9&Description=test&Shp_order=abc%2B1'
			. '&MerchantLogin=login&SignatureValue=46e153d00db13ba3848be7d08f1cf62f';
	}

	private function expectedJwtBody(): string {
		return '"eyJhbGciOiJNRDUiLCJ0eXAiOiJKV1QifQ.'
			. 'eyJNZXJjaGFudExvZ2luIjoibG9naW4iLCJJbnZvaWNlVHlwZSI6Ik9uZVRpbWUiLCJDdWx0dXJlIjoicnUiLCJJbnZJZCI6MSwiT3V0U3VtIjoxLCJEZXNjcmlwdGlvbiI6InRlc3QifQ.'
			. 'FKHP-6TuMui4tsnqUvjumw"';
	}

	private function expectedRecurringBody(): string {
		return 'OutSum=100.00&InvoiceID=200002&PreviousInvoiceID=200001&Description=Recurring+payment'
			. '&Receipt=%257B%2522items%2522%253A%255B%257B%2522name%2522%253A%2522Subscription%2522%252C'
			. '%2522quantity%2522%253A1%252C%2522sum%2522%253A100%252C%2522payment_method%2522%253A'
			. '%2522full_payment%2522%252C%2522payment_object%2522%253A%2522service%2522%252C%2522tax%2522%253A'
			. '%2522none%2522%257D%255D%257D&Shp_order=abc%2B1&MerchantLogin=login'
			. '&SignatureValue=1be6746b70e8f702171f85e427399a9e';
	}

	private function decodeJwtPayloadFromLastBody(): array {
		$jwt = json_decode($this->http->lastBody, true);
		$parts = explode('.', $jwt);
		$payload = strtr($parts[1], '-_', '+/');
		$payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);

		return json_decode(base64_decode($payload), true);
	}

	private function statusFilters(): array {
		return array(
			'CurrentPage' => 1,
			'PageSize' => 1,
			'InvoiceStatuses' => array('paid'),
			'DateFrom' => '2024-01-01',
			'DateTo' => '2024-01-02',
			'InvoiceTypes' => array('onetime'),
		);
	}

	private function assertExceptionChainDoesNotContain(\Throwable $exception, array $needles): void {
		do {
			foreach ($needles as $needle) {
				$this->assertStringNotContainsString($needle, $exception->getMessage());
			}
			$exception = $exception->getPrevious();
		} while ($exception !== null);
	}
}

class FixedSignatureService extends SignatureService {
	/** @var int */
	public $jwtSignCalls = 0;

	public function jwtSignMd5($dataToSign, $merchantLogin, $password1) {
		$this->jwtSignCalls++;
		return 'fixed-signature';
	}
}
