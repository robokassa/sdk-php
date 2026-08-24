<?php
namespace Robokassa\Client;

final class Response {
	/** @var string */
	public $body;

	/** @var int */
	public $status;

	public function __construct(string $body, int $status) {
		$this->body = $body;
		$this->status = $status;
	}
}
