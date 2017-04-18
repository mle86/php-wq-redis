<?php
namespace mle86\WQ\Tests;

use mle86\WQ\WorkServerAdapter\WorkServerAdapter;
use mle86\WQ\WorkServerAdapter\RedisWorkServer;
use Redis;

require_once __DIR__.'/../vendor/mle86/wq/test/helper/AbstractWorkServerAdapterTest.php';

class RedisServerTest
	extends AbstractWorkServerAdapterTest
{

	private const DEFAULT_REDIS_PORT = 6379;

	public function getWorkServerAdapter () : WorkServerAdapter {
		$r = new Redis;
		$this->assertTrue($r->connect("localhost", self::DEFAULT_REDIS_PORT),
			"Redis::connect() failed!");
		return new RedisWorkServer ($r);
	}

}

