<?php

namespace  MWStake\MediaWiki\Component\ProcessManager;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
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
	 * @param array|null $data
	 * @param string|null $pid ID of the exsting process to continue
	 * @return string ProcessID
	 */
	public function start( ProcessManager $manager, $data = [], $pid = null, $sync ) {
		$scriptPath = dirname( __DIR__ ) . '/maintenance/processExecution.php';
		$maintenancePath = $GLOBALS['IP'] . '/maintenance/Maintenance.php';

		$pid = $pid ?? md5( rand( 1, 9999999 ) + ( new \DateTime() )->getTimestamp() );
		$manager->recordStart( $pid, $this->steps, $this->timeout );
		$phpBinaryFinder = new ExecutableFinder();
		$phpBinaryPath = $phpBinaryFinder->find( 'php' );
		if ( !$phpBinaryPath ) {
			$manager->recordFinish(
				$pid, 1, "PHP executable cannot be found"
			);
			return $pid;
		}
		if ( !file_exists( $maintenancePath ) ) {
			$manager->recordFinish(
				$pid, 1, "Paths does not exist: $maintenancePath"
			);
			return $pid;
		}

		$this->parentProcess = new Process( [
			$phpBinaryPath, $scriptPath, $maintenancePath, $pid
		] );
		$input = new InputStream();
		$input->write( json_encode( [ 'steps' => $this->steps, 'data' => $data ] ) );
		$this->parentProcess->setInput( $input );
		$this->parentProcess->setTimeout( $this->timeout );

		if ( $sync ) {
			$err = '';
			try {
				$this->parentProcess->start( static function ( $type, $buffer ) use ( &$err ) {
					error_log( $buffer );
					if ( $type === Process::ERR ) {
						$err .= $buffer;
					}
				} );
				$input->close();
				$this->parentProcess->wait();
				if ( $manager->getProcessStatus( $pid ) !== Process::STATUS_STARTED ) {
					return $pid;
				} else {
					$manager->recordFinish(
						$pid, $this->parentProcess->getExitCode(), $this->parentProcess->getExitCodeText(), $err
					);
				}
			} catch ( ProcessTimedOutException $ex ) {
				$manager->recordFinish( $pid, 152, 'timeout', [ 'stderr' => $err ] );
			}
		} else {
			$this->parentProcess->start();
			$input->close();
		}

		return $pid;
	}

	/**
	 * @return Process
	 */
	public function getParentProcess(): Process {
		return $this->parentProcess;
	}
}
