<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

interface IProcessStep {
	/**
	 * Execute step
	 * Throw exception on failure
	 * @param array|null $data from previous step
	 * @return void
	 */
	public function execute( $data = [] ): array;
}
