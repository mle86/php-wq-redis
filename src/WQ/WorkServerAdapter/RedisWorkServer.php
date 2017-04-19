<?php
namespace mle86\WQ\WorkServerAdapter;

use mle86\WQ\Exception\UnserializationException;
use mle86\WQ\Job\Job;
use mle86\WQ\Job\QueueEntry;
use Redis;

/**
 * This Adapter class implements the {@see WorkServerAdapter} interface.
 *
 * It connects to a Redis server.
 *
 * Because Redis does not have
 * delayed entries,
 * reserved entries,
 * or buried entries,
 * this class uses several custom workarounds
 * to emulate those features.
 *
 * For every $workQueue used,
 * this class will create multiple Redis keys:
 * - <tt>\_wq.$workQueue</tt>  (ready jobs – List)
 * - <tt>\_wq_delay.$workQueue</tt>  (delayed jobs – Ordered Set)
 * - <tt>\_wq_buried.$workQueue</tt>  (buried jobs – List)
 *
 * @see http://stackoverflow.com/a/15016319  The delaying mechanism was inspired by this StackOverflow response.
 */
class RedisWorkServer
	implements WorkServerAdapter
{

	const APPEND_DELAYED_JOBS = true;

	const DEFAULT_REDIS_PORT = 6379;


	/** @var Redis */
	protected $redis;

	/**
	 * Constructor.
	 * Takes an already-configured {@see Pheanstalk} instance to work with.
	 * Does not attempt to establish a connection itself --
	 * use the {@see connect()} factory method for that instead
	 * or do it with {@see Redis::connect()} prior to using this constructor.
	 *
	 * @param Redis $serverConnection
	 */
	public function __construct (Redis $serverConnection) {
		$this->redis = $serverConnection;
	}

	/**
	 * Factory method.
	 * This will create a new {@see Redis} instance by itself.
	 *
	 * See {@see Redis::connect()} for the parameter descriptions.
	 *
	 * @param string $host
	 * @param int $port
	 * @param float $timeout
	 * @param int $retry_interval
	 * @return self
	 */
	public static function connect (string $host = "localhost", int $port = self::DEFAULT_REDIS_PORT, float $timeout = 0.0, int $retry_interval = 0) {
		$redis = new Redis;
		if (!$redis->connect($host, $port, $timeout, $retry_interval)) {
			throw new \RuntimeException ("Redis connection failed");
		}
		return new self ($redis);
	}


	public function getNextQueueEntry ($workQueue, int $timeout = self::DEFAULT_TIMEOUT) : ?QueueEntry {
		$this->activateDelayedJobs((array)$workQueue);

		if ($timeout === self::NOBLOCK) {
			$entry = $this->multiLPOP($workQueue);
		} else {
			$entry = $this->fetchQueueEntry($workQueue, $timeout);
		}

		if (is_array($entry)) {
			// LPOP returns a string, BLPOP and multiLPOP() return a [fromKey,content] array
			$workQueue = $entry[0] ?? null;
			$entry     = $entry[1] ?? null;
		}

		if (empty($entry)) {
			return null;
		}

		try {
			return QueueEntry::fromSerializedJob(
				$entry,
				$workQueue,
				$entry,
				"");
		} catch (UnserializationException $e) {
			$this->redis->rpush(self::buriedKey($workQueue), $entry);
			throw $e;
		}
	}

	/**
	 * Redis does not have "delayed" entries --
	 * we have to do that ourselves.
	 * So for a long timeout, it's not enough to just call activateDelayedJobs() once at the beginning,
	 * because one of the actication timestamps might be during our waiting delay --
	 * and it won't activate automatically.
	 * So instead we'll determine the next activation timestamp (if any),
	 * wait until we reach it,
	 * then call activateDelayedJobs() once,
	 * then use BLPOP again with the rest of the timeout.
	 *
	 * @param string|string[] $workQueue
	 * @param int $timeout  Any positive timeout, or the FOREVER value
	 */
	private function fetchQueueEntry ($workQueue, int $timeout) {
		if ($timeout === self::FOREVER) {
			$timeout = 60 * 60 * 24 * 365 * 10;
		}
		
		$next_ts = $this->nextActivationTimestamp((array)$workQueue);
		if ($next_ts > 0 && $next_ts <= time() + $timeout) {
			$first_delay = max(1, $next_ts - time());
			$timeout     = $timeout - $first_delay;  // rest delay

			$raw_entry = $this->redis->blPop(self::queueKeys((array)$workQueue), $first_delay);
			if (!empty($raw_entry)) {
				return $raw_entry;
			}

			$this->activateDelayedJobs((array)$workQueue);
		}

		return ($timeout >= 1)
			? $this->redis->blPop(self::queueKeys((array)$workQueue), $timeout)
			: $this->multiLPOP($workQueue);
	}

	private function nextActivationTimestamp (array $workQueues) : ?int {
		$minTimestamp = null;

		foreach ($workQueues as $workQueue) {
			$delayedKey = self::delayedKey($workQueue);
			$firstEntry = $this->redis->zrange($delayedKey, 0, 0, true);
			if (!empty($firstEntry)) {
				$firstTimestamp = ceil(reset($firstEntry));
				$minTimestamp   = (isset($minTimestamp))
					? min($minTimestamp, $firstTimestamp)
					: $firstTimestamp;
			}
		}

		return $minTimestamp;
	}

	/**
	 * Checks the work queue and activates any delayed jobs whose delay interval is up.
	 *
	 * @param string[] $workQueues
	 */
	private function activateDelayedJobs (array $workQueues) {
		foreach ($workQueues as $workQueue) {
			$delayedKey = self::delayedKey($workQueue);
			$queueKey   = self::queueKey($workQueue);

			$epsilon = 0.0001;

			if ($this->redis->zCard($delayedKey) <= 0) {
				// early return: there are no delayed jobs
				continue;
			}

			do {
				/* This loop ensures that we get all delayed jobs whose delay is over,
				 * deleting them from their delaying Sorted Set
				 * while avoiding race conditions.
				 * This is done by wrapping the deletion in a transaction
				 * while watching the delaying sorted set for any changes,
				 * and retrying the process if there's been some conflict.  */

				$now = time() + $epsilon;
				$this->redis->watch($delayedKey);
				$jobs = $this->redis->zRangeByScore($delayedKey, '-inf', $now);

				if (empty($jobs)) {
					// early return: there are delayed jobs, but none that are ready now
					$this->redis->unwatch();
					continue 2;
				}

				/** @noinspection PhpUndefinedMethodInspection */
				$ret = $this->redis->multi()
					->zRemRangeByScore($delayedKey, '-inf', $now)
					->exec();

			} while ($ret === false || $ret === null);

			// Now store the jobs in the actual work queue:

			if (self::APPEND_DELAYED_JOBS) {
				foreach ($jobs as $job) {
					$this->redis->rPush($queueKey, $job);
				}
			} else {
				foreach ($jobs as $job) {
					$this->redis->lPush($queueKey, $job);
				}
			}
		}
	}

	/**
	 * Returns the first entry from any of the specified queue names,
	 * or NULL if all of those queues are empty at the moment.
	 *
	 * Has the same return format as BLPOP:
	 * <tt>[fromKey, content]</tt>.
	 *
	 * @param string|string[] $workQueues
	 * @return array|null
	 */
	private function multiLPOP ($workQueues) : ?array {
		foreach ((array)$workQueues as $workQueue) {
			$ret = $this->redis->lPop(self::queueKey($workQueue));
			if (!empty($ret)) {
				return [$workQueue, $ret];
				// emulates BLPOP's return format
			}
		}
		return null;
	}

	private static function queueKey (string $workQueue) {
		return "_wq.{$workQueue}";
	}

	private static function delayedKey (string $workQueue) {
		return "_wq_delay.{$workQueue}";
	}

	private static function buriedKey (string $workQueue) {
		return "_wq_buried.{$workQueue}";
	}

	private static function queueKeys (array $workQueues) {
		foreach ($workQueues as &$wq) {
			$wq = self::queueKey($wq);
		}
		return $workQueues;
	}
#	private static function delayedKeys (array $workQueues) {
#		foreach ($workQueues as &$wq) {
#			$wq = self::delayedKey($wq);
#		}
#		return $workQueues;
#	}
#	private static function buriedKeys (array $workQueues) {
#		foreach ($workQueues as &$wq) {
#			$wq = self::buriedKey($wq);
#		}
#		return $workQueues;
#	}

	/**
	 * Stores a job in the work queue for later processing.
	 *
	 * @param string $workQueue The name of the Work Queue to store the job in.
	 * @param Job $job The job to store.
	 * @param int $delay  The job delay in seconds after which it will become available to {@see getNextQueueEntry()}.
	 *                    Set to zero (default) for jobs which should be processed as soon as possible.
	 */
	public function storeJob (string $workQueue, Job $job, int $delay = 0) {
		$serializedData = serialize($job);
		if ($delay > 0) {
			$delayedKey = self::delayedKey($workQueue);
			$activationTimestamp = time() + $delay;
			$this->redis->zAdd($delayedKey, $activationTimestamp, $serializedData);
		} else {
			$queueKey = self::queueKey($workQueue);
			$this->redis->rPush($queueKey, $serializedData);
		}
	}

	/**
	 * Buries an existing job
	 * so that it won't be returned by {@see getNextQueueEntry()} again
	 * but is still present in the system for manual inspection.
	 *
	 * This is what happens to failed jobs.
	 *
	 * @param QueueEntry $entry
	 */
	public function buryEntry (QueueEntry $entry) {
		$this->redis->rPush(
			self::buriedKey($entry->getWorkQueue()),
			$entry->getHandle() );
	}

	/**
	 * Re-queues an existing job
	 * so that it can be returned by {@see getNextQueueEntry()}
	 * again at some later time.
	 * A {@see $delay} is required
	 * to prevent the job from being returned right after it was re-queued.
	 *
	 * This is what happens to failed jobs which can still be re-queued for a retry.
	 *
	 * @param QueueEntry $entry The job to re-queue. The instance should not be used anymore after this call.
	 * @param int $delay The job delay in seconds. It will become available for {@see getNextQueueEntry()} after this delay.
	 * @param string|null $workQueue By default, to job is re-queued into its original Work Queue ({@see QueueEntry::getWorkQueue}).
	 *                                With this parameter, a different Work Queue can be chosen.
	 */
	public function requeueEntry (QueueEntry $entry, int $delay, string $workQueue = null) {
		$queueName = $workQueue ?? $entry->getWorkQueue();
		$this->storeJob($queueName, $entry->getJob(), $delay);
	}

	/**
	 * Permanently deletes a job entry for its work queue.
	 *
	 * This is what happens to finished jobs.
	 *
	 * @param QueueEntry $entry The job to delete.
	 */
	public function deleteEntry (QueueEntry $entry) {
		// nop, we already removed the entry from the queue with LPOP
	}

}
