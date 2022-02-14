<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

interface InterruptingProcessStep extends IProcessStep {
	public const STATUS_INTERRUPTED = 'interrupted';
}
