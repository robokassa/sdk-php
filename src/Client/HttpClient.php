<?php
namespace Robokassa\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Robokassa\Exception\RobokassaException;

final class HttpClient implements HttpClientInterface {
	/** @var Client */
	private $client;

	public function __construct(?Client $client = null) {
		$this->client = $client ?? new Client(['timeout' => 15]);
	}

	public function get(string $url, array $headers = []): Response {
		return $this->request('get', $url, null, $headers);
	}

	public function post(string $url, string $body, array $headers = []): Response {
		return $this->request('post', $url, $body, $headers);
	}

	/**
	 * Выполняет HTTP-запрос и заворачивает сетевые ошибки в исключение SDK.
	 *
	 * @param string $method
	 * @param string $url
	 * @param string|null $body
	 * @param array $headers
	 * @return Response
	 * @throws RobokassaException
	 */
	private function request(string $method, string $url, ?string $body, array $headers): Response {
		$options = array(
			'headers' => $headers,
		);
		if ($body !== null) {
			$options['body'] = $body;
		}

		try {
			$r = $this->client->{$method}($url, $options);
			return new Response((string)$r->getBody(), $r->getStatusCode());
		} catch (RequestException $e) {
			$response = $e->getResponse();
			if ($response !== null) {
				throw new RobokassaException(
					'Ошибка HTTP ' . strtoupper($method) . ': HTTP Status: ' . $response->getStatusCode()
				);
			}
			throw new RobokassaException('Сетевая ошибка HTTP ' . strtoupper($method));
		} catch (GuzzleException $e) {
			throw new RobokassaException('Сетевая ошибка HTTP ' . strtoupper($method));
		}
	}
}
