<?php

namespace  MWStake\MediaWiki\Component\ProcessManager;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class ManagedProcess {
	private $parentProcess;
	private $steps;
	private $timeout;


	public function __construct( array $steps, $timeout = 60 ) {
		$this->steps = $steps;
		$this->timeout = $timeout;
	}

	public function start( ProcessManager $manager ) {
		$phpBinaryFinder = new ExecutableFinder();
		$phpBinaryPath = $phpBinaryFinder->find( 'php' );
		$scriptPath = dirname( __DIR__ ) . '/maintenance/processExecution.php';
		$maintenancePath = $GLOBALS['IP'] . '/maintenance/Maintenance.php';
		$autoloaderPath = dirname( dirname( dirname( __DIR__ )  ) ) . '/autoload.php';

		$pid = md5( rand( 1, 9999999 ) + ( new \DateTime() )->getTimestamp() );
		$this->parentProcess = new AsyncProcess( [
			$phpBinaryPath, $scriptPath, $maintenancePath, $autoloaderPath, $pid
		] );
		$input = new InputStream();
		$input->write( json_encode( $this->steps ) );
		$this->parentProcess->setInput( $input );
		$this->parentProcess->setTimeout( $this->timeout );
		$manager->recordStart( $pid, $this->timeout );
		//$this->parentProcess->disableOutput();
		$this->parentProcess->start();

		if ( !$this->parentProcess->isRunning() ) {
			// Process already done, insert dummy entry
			$manager->recordStart( $pid, $this->timeout );
			$manager->recordFinish(
				$pid, $this->parentProcess->getExitCode(),
				$this->parentProcess->getExitCodeText()
			);
		}

		return $pid;
	}

	public function getParentProcess(): Process {
		return $this->parentProcess;
	}
}
