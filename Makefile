#!/usr/bin/make -f

all:

COMPOSER=./composer.phar

TEST_CONTAINER=mle86/php-wq-redis-test
TEST_CONTAINER_VERSION=1.0.0


# dep: Install dependencies necessary for development work on this library.
dep: $(COMPOSER)
	[ -d vendor/ ] || $(COMPOSER) install

# composer.phar: Get composer binary from authoritative source
$(COMPOSER):
	curl -sS https://getcomposer.org/installer | php

# update: Updates all composer dependencies of this library.
update: $(COMPOSER)
	$(COMPOSER) update


test-container:
	[ -n "`docker images -q '$(TEST_CONTAINER):$(TEST_CONTAINER_VERSION)'`" ] \
	|| docker build --tag $(TEST_CONTAINER):$(TEST_CONTAINER_VERSION) .

# test: Executes all phpUnit tests according to the local phpunit.xml.dist file.
test: dep test-container
	docker run --rm  --volume "`pwd`":/mnt:ro  $(TEST_CONTAINER):$(TEST_CONTAINER_VERSION)  vendor/bin/phpunit -v

clean:
	docker rmi $(TEST_CONTAINER):$(TEST_CONTAINER_VERSION)  || true

