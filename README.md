# Process Manager

This library allows you to create async background processes, that can be accessed later from anywhere,
to check the progress and retrieve output.

# Usage

Process works based on steps provided to it, it will execute steps sequentialy, passing output data from one step
as an input for the next, until the end. Last step will return its output as the output of the whole process.

Steps are defined as `ObjectFactory` specs. Object produced from such specs must be instance of `MWStake\MediaWiki\Component\ProcessManager\IProcessStep`.

## Sample step
```php
class Foo implements IProcessStep {

	/** @var ILoadBalancer */
	private $lb;
	/** @var string */
	private $name;

	public function __construct( ILoadBalancer $lb, $name ) {
		$this->lb = $lb;
		$this->name = $name;
	}

	public function execute( $data = [] ): array  {
	// Add "_bar" to the name passed as the argument in the spec and return it
		$name = $this->name . '_bar';

		// some lenghty code

		return [ 'modifiedName' => $name ];
	}
}
```

## Creating process
```php
// Create process that has a single step, Foo, defined above
// new ManagerProcess( array $steps, int $timeout );
$process = new ManagedProcess( [
	'foo-step' => [
		'class' => Foo::class,
		'args' => [ 'Bar-name' ],
		'services' => [ 'DBLoadBalancer' ]
	]
], 300 );

$processManager = MediaWikiServices::getInstance()->getService( 'ProcessManager' );

// ProcessManager::startProcess() returns unique process ID that is required
// later on to check on the process state
echo $processManager->startProcess( $process );
// 1211a33123aae2baa6ed1d9a1846da9d
```

## Checking process status

Once the process is started using the procedure above, and we obtain the process id, we can check on its status
anytime, from anywhere, even from different process then the one that started the process

```php
$processManager = MediaWikiServices::getInstance()->getService( 'ProcessManager' );
echo $processManager->getProcessInfo( $pid );
// Returns JSON
{
	"pid": "1211a33123aae2baa6ed1d9a1846da9d",
	"started_at": "20220209125814",
	"status": "finished",
	"output": { /*JSON-encoded string of whatever the last step returned as output*/ }
}
```

In case of an error, response will contain status `error`, and show Exception message and callstack.

## Interrupting processes
Sometimes, we want to pause between steps, and re-evaluate data returned.

This can be achieved if step implements `MWStake\MediaWiki\Component\ProcessManager\InterruptingProcessStep` instead of `MWStake\MediaWiki\Component\ProcessManager\IProcessStep`.
In case process comes across an instance of this interface, it will pause the processing and report back data that was returned from the step.

To continue the process, you must call `$processManager->proceed( $pid, $data )`. In this case, `$pid` is the ID of the paused process, 
and `$data` is any modified data to be passed to the next step. This data will be merged with data returned from previous step (the one that paused the process).
This call will return the PID of the process, which should be the same as the one passed (same process continues).

## Notes

- This lib requires an DB table, so `update.php` will be necessary
- Old jobs will be deleted 10h after they are created
- Known issue: If your code (in the step) has an un-catchable error (not passing required param, syntax errors), process will
not be able to record those and will just crash. Only way to track such processes would be to actually look into the kernel,
  which is not supported for now. Such jobs will just remain in `running` state until the timeout, after which they will fail
  with timeout exception.
