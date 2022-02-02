<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

interface IProcessStep {
	/**
	 * Execute step
	 * Throw exception on failure
	 *
	 * @return void
	 */
	public function execute();
}
