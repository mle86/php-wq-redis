<?php
namespace mle86\WQ\Tests;

use mle86\WQ\Testing\AbstractWorkServerAdapterTest;
use mle86\WQ\WorkServerAdapter\WorkServerAdapter;
use mle86\WQ\WorkServerAdapter\RedisWorkServer;
use PHPUnit\Framework\Error\Error;
use Redis;

class RedisServerTest
    extends AbstractWorkServerAdapterTest
{

    public function checkEnvironment(): void
    {
        $this->checkInDocker();

        $this->assertNotFalse(getenv('REDIS_PORT'),
            "No REDIS_PORT ENV variable found! Is this test running in the test container?");
        $this->assertGreaterThan(1024, getenv('REDIS_PORT'),
            "Invalid REDIS_PORT ENV variable!");
        $this->assertNotEquals(RedisWorkServer::DEFAULT_REDIS_PORT, getenv('REDIS_PORT'),
            "REDIS_PORT ENV variable should NOT be set to the default Redis port! " .
            "This prevents the test scripts from accidentally running on the host system.");

        $ret = null;
        try {
            $ret = (new Redis)->connect("localhost", RedisWorkServer::DEFAULT_REDIS_PORT);
        } catch (Error | \RedisException $e) {
            // don't care
        }
        $this->assertNotTrue($ret,
            "We managed to get a Redis connection on the Redis default port! " .
            "This should not be possible inside the test container.");
    }

    public function getWorkServerAdapter(): WorkServerAdapter
    {
        return RedisWorkServer::connect("localhost", (int)getenv('REDIS_PORT'));
    }

}
