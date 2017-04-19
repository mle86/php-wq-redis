<?php
namespace mle86\WQ\Tests;

use mle86\WQ\WorkServerAdapter\WorkServerAdapter;
use mle86\WQ\WorkServerAdapter\RedisWorkServer;
use Redis;

require_once __DIR__.'/../vendor/mle86/wq/test/helper/AbstractWorkServerAdapterTest.php';

class RedisServerTest
	extends AbstractWorkServerAdapterTest
{

	public function checkEnvironment () {
		$this->checkInDocker();

		$this->assertTrue((getenv('REDIS_PORT') !== false),
			"No REDIS_PORT ENV variable found! Is this test running in the test container?");
		$this->assertGreaterThan(1024, getenv('REDIS_PORT'),
			"Invalid REDIS_PORT ENV variable!");
		$this->assertNotEquals(RedisWorkServer::DEFAULT_REDIS_PORT, getenv('REDIS_PORT'),
			"REDIS_PORT ENV variable should NOT be set to the default Redis port! " .
			"This prevents the test scripts from accidentally running on the host system.");

		$ret = null;
		try {
			$ret = (new Redis)->connect("localhost", RedisWorkServer::DEFAULT_REDIS_PORT);
		} catch (\PHPUnit_Framework_Error_Warning | \RedisException $e) {
			// don't care
		}
		$this->assertTrue(($ret !== true),
			"We managed to get a Redis connection on the Redis default port! " .
			"This should not be possible inside the test container.");
	}

	public function getWorkServerAdapter () : WorkServerAdapter {
		return RedisWorkServer::connect("localhost", (int)getenv('REDIS_PORT'));
	}

}

