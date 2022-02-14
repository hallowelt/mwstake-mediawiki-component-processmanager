<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

use Symfony\Component\Process\Process;

class AsyncProcess extends Process {

	/**
	 * @inheritDoc
	 */
	public function __destruct() {
		// Do not send SIGTERM once the calling code finishes
 	}
}
