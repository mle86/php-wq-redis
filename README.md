# WQ-Redis  (`mle86/wq-redis`)

This package contains the PHP class
`mle86\WQ\WorkServerAdapter\`**`RedisWorkServer`**.

It supplements the
[**mle86/wq**](https://github.com/mle86/php-wq) package
by implementing its `WorkServerAdapter` interface.

It connects to a [Redis](https://redis.io/) server
using the [phpredis](https://pecl.php.net/package/redis) extension.


# Version and Compatibility

This is
**version 1.0.0**
of `mle86/wq-redis`.

It was developed for
version 1.0.0
of `mle86/wq`
and should be compatible
with all of its future 1.x versions as well.


# Installation

```
$ sudo apt install php-redis  # to install the phpredis extension
$ composer require mle86/wq-redis
```


# Class reference

(<code>class mle86\WQ\WorkServerAdapter\\<b>RedisWorkServer</b> implements WorkServerAdapter</code>)

It connects to a Redis server.

Because Redis does not have
delayed entries,
reserved entries,
or buried entries,
this class uses several custom workarounds
to emulate those features.

For every `$workQueue` used,
this class will create multiple Redis keys:

* `_wq.`*`$workQueue`*  (ready jobs – List)
* `_wq_delay.`*`$workQueue`*  (delayed jobs – Ordered Set)
* `_wq_buried.`*`$workQueue`*  (buried jobs – List)

The delaying mechanism was inspired by
[this StackOverflow response](http://stackoverflow.com/a/15016319).

* <code>public function <b>__construct</b> (\Redis $serverConnection)</code>  
    Takes an already-configured `Redis` instance to work with.
    Does not attempt to establish a connection itself –
    use the `connect()` factory method for that instead
    or do it with `Redis::connect()` prior to using this constructor.
* <code>public function <b>connect</b> ($host = "localhost", $port = 6379, $timeout = 0.0, $retry_interval = 0)</code>  
    Factory method.
    This will create a new `Redis` instance by itself.  
    See [`Redis::connect()`](https://github.com/phpredis/phpredis#connect-open) for the parameter descriptions.

Interface methods
which are documented in the [`WorkServerAdapter`](https://github.com/mle86/php-wq#workserveradapter-interface) interface:

* <code>public function <b>storeJob</b> (string $workQueue, Job $job, int $delay = 0)</code>
* <code>public function <b>getNextQueueEntry</b> ($workQueue, int $timeout = DEFAULT_TIMEOUT) : ?QueueEntry</code>
* <code>public function <b>buryEntry</b> (QueueEntry $entry)</code>
* <code>public function <b>requeueEntry</b> (QueueEntry $entry, int $delay, string $workQueue = null)</code>
* <code>public function <b>deleteEntry</b> (QueueEntry $entry)</code>


# Usage example

```php
<?php
use mle86\WQ\WorkProcessor;
use mle86\WQ\WorkServerAdapter\RedisWorkServer;

$processor = new WorkProcessor( new RedisWorkServer("localhost") );

while (true) {
    $processor->executeNextJob("webhook");
}
```

This executes all jobs available in the local Redis server's “`webhook`” queue, forever.
It will however abort if one of the jobs throws an exception –
you might want to add a logging try-catch block around the `executeNextJob()` call
as shown in [WQ's “Minimal Example”](https://github.com/mle86/php-wq#minimal-example).

