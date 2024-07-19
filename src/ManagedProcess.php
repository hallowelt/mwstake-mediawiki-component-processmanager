<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

class ManagedProcess {
	/** @var array */
	private $steps;
	/** @var int */
	private $timeout;
	/** @var array|null */
	private $additionalArgs;

	/**
	 * @param array $steps
	 * @param int|null $timeout
	 * @param array|null $additionalArgs
	 */
	public function __construct( array $steps, ?int $timeout = 60, ?array $additionalArgs = null ) {
		$this->steps = $steps;
		$this->timeout = $timeout;
		$this->additionalArgs = $additionalArgs;
	}

	/**
	 * @return array
	 */
	public function getSteps(): array {
		return $this->steps;
	}

	/**
	 * @return array|null
	 */
	public function getAdditionalArgs(): ?array {
		return $this->additionalArgs;
	}

	/**
	 * @return int|null
	 */
	public function getTimeout(): ?int {
		return $this->timeout;
	}

	/**
	 * @param array|null $data
	 * @return void
	 */
	public function setAdditionalArgs( ?array $data ) {
		$this->additionalArgs = $data;
	}
}
