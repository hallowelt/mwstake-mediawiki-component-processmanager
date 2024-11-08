<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

interface IProcessManagerPlugin {

	/**
	 * Called on on each run of ProcessRunner or in 1-minute-interval
	 * @return ProcessInfo|null
	 */
	public function run( ProcessManager $manager ): ?ProcessInfo;

	/**
	 * Symbolic name of the plugin
	 * @return string
	 */
	public function getKey(): string;
}