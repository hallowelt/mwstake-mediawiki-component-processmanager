<?php

namespace  MWStake\MediaWiki\Component\ProcessManager;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class ManagedProcess {
	/** @var Process|null */
	private $parentProcess = null;
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

	/**
	 * @param ProcessManager $manager
	 * @return string ProcessID
	 */
	public function start( ProcessManager $manager ) {
		$phpBinaryFinder = new ExecutableFinder();
		$phpBinaryPath = $phpBinaryFinder->find( 'php' );
		$scriptPath = dirname( __DIR__ ) . '/maintenance/processExecution.php';
		$maintenancePath = $GLOBALS['IP'] . '/maintenance/Maintenance.php';
		$autoloaderPath = dirname( dirname( dirname( __DIR__ ) ) ) . '/autoload.php';

		$pid = md5( rand( 1, 9999999 ) + ( new \DateTime() )->getTimestamp() );
		$this->parentProcess = new AsyncProcess( [
			$phpBinaryPath, $scriptPath, $maintenancePath, $autoloaderPath, $pid
		] );
		$input = new InputStream();
		$input->write( json_encode( $this->steps ) );
		$this->parentProcess->setInput( $input );
		$this->parentProcess->setTimeout( $this->timeout );
		$manager->recordStart( $pid, $this->timeout );
		// $this->parentProcess->disableOutput();
		$this->parentProcess->start();

		return $pid;
	}

	/**
	 * @return Process
	 */
	public function getParentProcess(): Process {
		return $this->parentProcess;
	}
}
