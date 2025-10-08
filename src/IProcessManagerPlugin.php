<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

interface IProcessManagerPlugin {

	/**
	 * Called on on each run of ProcessRunner or in 1-minute-interval
	 * @param ProcessManager $manager
	 * @param int|null $lastRun
	 * @return ProcessInfo[]
	 */
	public function run( ProcessManager $manager, ?int $lastRun ): array;

	/**
	 * Symbolic name of the plugin
	 * @return string
	 */
	public function getKey(): string;
}
