<?php

namespace  MWStake\MediaWiki\Component\ProcessManager;

use Symfony\Component\Process\Process;

class ManagedProcess {
	private $parentProcess;
	private $spec;
	private $timeout;


	public function __construct( array $spec, $timeout = 60 ) {
		$this->spec = $spec;
		$this->timeout = $timeout;
	}

	public function start( ProcessManager $manager ) {
		$this->parentProcess = new Process( /* ADD STUFF*/ );
		$this->parentProcess->setTimeout( $this->timeout );
		$this->parentProcess->disableOutput();

		$manager->recordStart( $this );
		if ( !$this->parentProcess->isRunning() ) {
			$manager->recordFinish(
				$this->parentProcess->getPid(),
				$this->parentProcess->getExitCode(),
				$this->parentProcess->getExitCode()
			);
		}

		return $this->parentProcess->getPid();
	}

	public function getParentProcess(): Process {
		return $this->parentProcess;
	}
}
