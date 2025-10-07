<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

interface IProcessQueue {

	/**
	 * @param string $pid
	 * @return ProcessInfo|null if no process found
	 */
	public function getProcessInfo( string $pid ): ?ProcessInfo;

	/**
	 * @param ManagedProcess $process
	 * @param array $data
	 * @return string|null PID of the process or null if process cannot be enqueued
	 */
	public function enqueueProcess( ManagedProcess $process, array $data ): ?string;

	/**
	 * Proceed with the next step of the process that was previously interrupted
	 *
	 * @param string $pid
	 * @return string|null New PID or null if processes cannot be continued
	 */
	public function proceed( $pid ): ?string;

	/**
	 * @param string $pid
	 * @param string $lastStep
	 * @param array $data
	 * @return bool
	 */
	public function recordInterrupt( string $pid, string $lastStep, array $data ): bool;

	/**
	 * Mark processes as started
	 *
	 * @param string $pid
	 * @return bool
	 */
	public function recordStart( string $pid ): bool;

	/**
	 * @param string $pid
	 * @param string $step
	 * @return bool
	 */
	public function storeLastCompletedStep( string $pid, string $step ): bool;

	/**
	 * @param string $pid
	 * @param int $exitCode
	 * @param string $exitStatus
	 * @param array $data
	 * @return bool
	 */
	public function recordFinish( string $pid, int $exitCode, string $exitStatus = '', array $data = [] ): bool;

	/**
	 * @return ProcessInfo|null Returns null if no process in the queue
	 */
	public function pluckOneFromQueue(): ?ProcessInfo;
}
