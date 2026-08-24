<?php
namespace Robokassa\Client;

interface HttpClientInterface {
	public function get(string $url, array $headers = []): Response;

	public function post(string $url, string $body, array $headers = []): Response;
}
