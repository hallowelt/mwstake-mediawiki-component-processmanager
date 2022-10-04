<?php

namespace  MWStake\MediaWiki\Component\ProcessManager;

class ManagedProcess {
	/** @var array */
	private $steps;
	/** @var int */
	private $timeout;

	/**
	 * @param array $steps
	 * @param int|null $timeout
	 */
	public function __construct( array $steps, ?int $timeout = 60 ) {
		$this->steps = $steps;
		$this->timeout = $timeout;
	}

	public function getSteps(): array {
		return $this->steps;
	}

	public function getTimeout(): ?int {
		return $this->timeout;
	}
}
