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
			$this->assertStringNotContainsString('secret-signature-network-error', $e->getMessage());
			$this->assertInstanceOf(ConnectException::class, $e->getPrevious());
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
			$this->assertStringNotContainsString('secret-signature-body', $e->getMessage());
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
}

class FixedSignatureService extends SignatureService {
	/** @var int */
	public $jwtSignCalls = 0;

	public function jwtSignMd5($dataToSign, $merchantLogin, $password1) {
		$this->jwtSignCalls++;
		return 'fixed-signature';
	}
}
