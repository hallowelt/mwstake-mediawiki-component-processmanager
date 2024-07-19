<?php

namespace MWStake\MediaWiki\Component\ProcessManager\ProcessQueue;

use DateInterval;
use DateTime;
use MWStake\MediaWiki\Component\ProcessManager\InterruptingProcessStep;
use MWStake\MediaWiki\Component\ProcessManager\IProcessQueue;
use MWStake\MediaWiki\Component\ProcessManager\ManagedProcess;
use MWStake\MediaWiki\Component\ProcessManager\ProcessInfo;
use Symfony\Component\Process\Process;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

class SimpleDatabaseQueue implements IProcessQueue {
	/** @var ILoadBalancer */
	private $lb;

	/**
	 * @param ILoadBalancer $lb
	 */
	public function __construct( ILoadBalancer $lb ) {
		$this->lb = $lb;
	}

	/**
	 * @inheritDoc
	 */
	public function getProcessInfo( string $pid ): ?ProcessInfo {
		return $this->loadProcess( $pid );
	}

	/**
	 * @inheritDoc
	 */
	public function enqueueProcess( ManagedProcess $process, array $data ): ?string {
		$pid = md5( rand( 1, 9999999 ) + ( new \DateTime() )->getTimestamp() );

		$res = $this->getDB()->insert(
			'processes',
			[
				'p_pid' => $pid,
				'p_state' => Process::STATUS_READY,
				'p_timeout' => $process->getTimeout(),
				'p_started' => $this->getDB()->timestamp( ( new DateTime() )->format( 'YmdHis' ) ),
				'p_output' => json_encode( $data ),
				'p_steps' => json_encode( $process->getSteps() ),
				'p_additional_script_args' => $process->getAdditionalArgs() ?
					json_encode( $process->getAdditionalArgs() ) : null,
			],
			__METHOD__
		);

		return $res ? $pid : null;
	}

	/**
	 * @inheritDoc
	 */
	public function proceed( $pid ): ?string {
		$info = $this->getProcessInfo( $pid );
		if ( !$info ) {
			throw new \Exception( 'Process with PID ' . $pid . ' does not exist' );
		}
		if ( $info->getState() !== InterruptingProcessStep::STATUS_INTERRUPTED ) {
			throw new \Exception( 'Process was not previously interrupted' );
		}
		$steps = $info->getSteps();
		$lastData = $info->getOutput();
		$lastStep = $lastData['lastStep'] ?? null;
		$lastData = $lastData['data'] ?? [];
		if ( !$lastStep ) {
			throw new \Exception( 'No last step information available' );
		}
		$remainingSteps = [];
		$found = false;
		foreach ( $steps as $name => $spec ) {
			if ( $name === $lastStep ) {
				$found = true;
				continue;
			}
			if ( $found ) {
				$remainingSteps[$name] = $spec;
			}
		}

		if ( empty( $remainingSteps ) ) {
			$this->recordFinish( $pid, 0, 'No steps left after proceeding', $lastData );
			return $pid;
		}

		$res = $this->updateInfo( $pid, [
			'p_state' => Process::STATUS_READY,
			'p_output' => json_encode( $lastData ),
			'p_steps' => json_encode( $remainingSteps )
		] );

		return $res ? $pid : null;
	}

	/**
	 * @inheritDoc
	 */
	public function recordInterrupt( string $pid, string $lastStep, array $data ): bool {
		return $this->updateInfo( $pid, [
			'p_state' => InterruptingProcessStep::STATUS_INTERRUPTED,
			'p_output' => json_encode( [
				'lastStep' => $lastStep,
				'data' => $data,
			] )
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function recordStart( string $pid ): bool {
		return $this->updateInfo( $pid, [
			'p_state' => Process::STATUS_STARTED,
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function storeLastCompletedStep( string $pid, string $step ): bool {
		return $this->updateInfo( $pid, [
			'p_last_completed_step' => $step,
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function recordFinish( string $pid, int $exitCode, string $exitStatus = '', array $data = [] ): bool {
		return $this->updateInfo( $pid, [
			'p_state' => Process::STATUS_TERMINATED,
			'p_exitcode' => $exitCode,
			'p_exitstatus' => $exitStatus,
			'p_output' => json_encode( $data )
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getEnqueuedProcesses(): array {
		$res = $this->getDB()->select(
			'processes',
			[
				'p_pid',
				'p_state',
				'p_exitcode',
				'p_exitstatus',
				'p_started',
				'p_timeout',
				'p_output',
				'p_steps',
				'p_last_completed_step',
				'p_additional_script_args'
			],
			[
				'p_state' => Process::STATUS_READY
			],
			__METHOD__
		);
		$processes = [];
		foreach ( $res as $row ) {
			$processes[] = ProcessInfo::newFromRow( $row );
		}
		return $processes;
	}

	/**
	 * @return IDatabase
	 */
	protected function getDB(): IDatabase {
		return $this->lb->getConnection( DB_PRIMARY );
	}

	/**
	 * @param string $pid
	 * @return ProcessInfo|null
	 */
	private function loadProcess( $pid ): ?ProcessInfo {
		$this->garbageCollect();

		$row = $this->getDB()->selectRow(
			'processes',
			[
				'p_pid',
				'p_state',
				'p_exitcode',
				'p_exitstatus',
				'p_started',
				'p_timeout',
				'p_output',
				'p_steps',
				'p_last_completed_step',
				'p_additional_script_args',
			],
			[
				'p_pid' => $pid
			],
			__METHOD__
		);

		if ( !$row ) {
			return null;
		}
		return ProcessInfo::newFromRow( $row );
	}

	/**
	 * @param string $pid
	 * @param array $data
	 * @return bool
	 */
	private function updateInfo( $pid, array $data ) {
		return $this->getDB()->update(
			'processes',
			$data,
			[ 'p_pid' => $pid ],
			__METHOD__
		);
	}

	/**
	 * Delete all processes older than 1 day
	 */
	private function garbageCollect() {
		$dayAgo = ( new DateTime() )->sub( new DateInterval( "PT1D" ) );
		$this->getDB()->delete(
			'processes',
			[
				'p_started < ' . $this->getDB()->timestamp( $dayAgo->format( 'YmdHis' ) ),
				// Status is not PROCESS_INTERRUPTED
				'p_state != ' . $this->getDB()->addQuotes( InterruptingProcessStep::STATUS_INTERRUPTED ),
			],
			__METHOD__
		);
	}
}
