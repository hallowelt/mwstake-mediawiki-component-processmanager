<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

/**
 * @note Here for backward compatibility
 */
class ProcessManager {

	/** @var IProcessQueue */
	private $processQueue;

	/**
	 * @param IProcessQueue $processQueue
	 */
	public function __construct( IProcessQueue $processQueue ) {
		$this->processQueue = $processQueue;
	}

	/**
	 * @param string $pid
	 * @return ProcessInfo|null
	 */
	public function getProcessInfo( $pid ): ?ProcessInfo {
		return $this->processQueue->getProcessInfo( $pid );
	}

	/**
	 * @param string $pid
	 * @return string|null
	 */
	public function getProcessStatus( $pid ): ?string {
		$info = $this->getProcessInfo( $pid );
		if ( $info ) {
			return $info->getState();
		}
		return null;
	}

	/**
	 * @param ManagedProcess $process
	 * @param array|null $data
	 * @return string
	 */
	public function startProcess( ManagedProcess $process, $data = [] ): string {
		return $this->processQueue->enqueueProcess( $process->getSteps(), $process->getTimeout(), $data );
	}

	/**
	 * Record end of the process
	 *
	 * @param string $pid
	 * @param int $exitCode
	 * @param string $exitStatus
	 * @param array|null $data
	 * @return bool
	 */
	public function recordFinish( $pid, int $exitCode, string $exitStatus = '', $data = [] ) {
		return $this->processQueue->recordFinish( $pid, $exitCode, $exitStatus, $data );
	}

	/**
	 * @param string $pid
	 * @param string $lastStep
	 * @param array $data
	 * @return bool
	 */
	public function recordInterrupt( $pid, $lastStep, $data ) {
		return $this->processQueue->recordInterrupt( $pid, $lastStep, $data );
	}

	/**
	 * @param string $pid
	 * @return string
	 * @throws \Exception
	 */
	public function proceed( $pid ): ?string {
		return $this->processQueue->proceed( $pid );
	}

	/**
	 * Record starting of the process
	 *
	 * @param string $pid
	 * @return bool
	 */
	public function recordStart( $pid ): bool {
		return $this->processQueue->recordStart( $pid );
	}

	/**
	 * @param string $pid
	 * @param string $step
	 * @return bool
	 */
	public function storeLastCompletedStep( string $pid, string $step ) {
		return $this->processQueue->storeLastCompletedStep( $pid, $step );
	}

	/**
	 * @return array
	 */
	public function getEnqueuedProcesses(): array {
		return $this->processQueue->getEnqueuedProcesses();
	}
}
