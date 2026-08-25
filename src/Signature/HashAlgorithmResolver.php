<?php
namespace Robokassa\Signature;

use Robokassa\Exception\RobokassaException;

class HashAlgorithmResolver {
	/** @var string[] */
	private $allowedAlgorithms;

	/** @var string */
	private $defaultAlgorithm;

	/**
	 * Создаёт объект выбора алгоритма подписи.
	 *
	 * @param string $defaultAlgorithm
	 * @param string[]|null $allowedAlgorithms
	 * @throws RobokassaException
	 */
	public function __construct($defaultAlgorithm = 'md5', ?array $allowedAlgorithms = null) {
		$this->allowedAlgorithms = $allowedAlgorithms ?: array(
			'md5',
			'ripemd160',
			'sha1',
			'sha256',
			'sha384',
			'sha512',
		);
		$this->defaultAlgorithm = $this->normalize($defaultAlgorithm);
	}

	/**
	 * Возвращает поддерживаемый алгоритм или выбрасывает исключение.
	 *
	 * @param string|null $algorithm
	 * @return string
	 * @throws RobokassaException
	 */
	public function resolve($algorithm = null): string {
		if ($algorithm === null) {
			return $this->defaultAlgorithm;
		}
		return $this->normalize($algorithm);
	}

	/**
	 * Возвращает список поддерживаемых алгоритмов.
	 *
	 * @return string[]
	 */
	public function getSupportedAlgorithms(): array {
		return $this->allowedAlgorithms;
	}

	/**
	 * Нормализует имя алгоритма.
	 *
	 * @param string $algorithm
	 * @return string
	 * @throws RobokassaException
	 */
	private function normalize($algorithm): string {
		$algorithm = strtolower((string)$algorithm);
		if (!in_array($algorithm, $this->allowedAlgorithms, true)) {
			throw new RobokassaException($this->buildUnknownAlgorithmMessage());
		}
		return $algorithm;
	}

	/**
	 * Формирует сообщение для неизвестного алгоритма.
	 *
	 * @return string
	 */
	private function buildUnknownAlgorithmMessage(): string {
		return 'Неизвестный алгоритм подписи. Поддерживаются: ' . implode(', ', $this->allowedAlgorithms);
	}
}
