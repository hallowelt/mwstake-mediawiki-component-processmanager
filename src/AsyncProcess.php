<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

use Symfony\Component\Process\Process;

class AsyncProcess extends Process {
	// Do not send SIGTERM once the calling code finishes
	public function __destruct() {
 }
}
