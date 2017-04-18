#!/bin/sh

if [ ! -f /.dockerenv ]; then
	echo "This script is supposed to be run inside a Docker container."  >&2
	echo "Try 'make test' instead."  >&2
	exit 1
fi

redis-server  --daemonize yes  --port $REDIS_PORT

exec "$@"
