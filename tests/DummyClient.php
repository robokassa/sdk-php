<?php
namespace Robokassa\Tests;

use Robokassa\Client\HttpClientInterface;
use Robokassa\Client\Response;

/**
 * Заглушка HTTP-клиента для тестов.
 */
class DummyClient implements HttpClientInterface {
	/** @var array<int, Response|\Throwable> */
	private array $responses = [];

	public string $lastUrl = '';
	public string $lastBody = '';
	public array $lastHeaders = [];

	/**
	 * Добавить ответ в очередь
	 */
	public function queueResponse(Response $response): void {
		$this->responses[] = $response;
	}

	/**
	 * Добавить исключение в очередь.
	 */
	public function queueException(\Throwable $exception): void {
		$this->responses[] = $exception;
	}

	/**
	 * Имитация запроса GET
	 */
	public function get(string $url, array $headers = []): Response {
		$this->lastUrl = $url;
		$this->lastHeaders = $headers;
		return $this->nextResponse();
	}

	/**
	 * Имитация запроса POST
	 */
	public function post(string $url, string $body, array $headers = []): Response {
		$this->lastUrl = $url;
		$this->lastBody = $body;
		$this->lastHeaders = $headers;
		return $this->nextResponse();
	}

	private function nextResponse(): Response {
		$response = array_shift($this->responses);
		if ($response instanceof \Throwable) {
			throw $response;
		}
		if (!$response instanceof Response) {
			throw new \RuntimeException('Не задан ответ HTTP-заглушки');
		}
		return $response;
	}
}
