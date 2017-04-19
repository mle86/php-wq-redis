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

`class mle86\WQ\WorkServerAdapter\`**`RedisWorkServer`** `implements WorkServerAdapter`

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

* `public function` **`__construct`** `(\Redis $serverConnection)`  
    Takes an already-configured `Redis` instance to work with.
    Does not attempt to establish a connection itself --
    use the `connect()` factory method for that instead
    or do it with `Redis::connect()` prior to using this constructor.
* `public function` **`connect`** `($host = "localhost", $port = 6379, $timeout = 0.0, $retry_interval = 0)`  
    Factory method.
    This will create a new `Redis` instance by itself.  
    See [`Redis::connect()`](https://github.com/phpredis/phpredis#connect-open) for the parameter descriptions.

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

