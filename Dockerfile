# see TESTING.md

FROM php:7.1-cli

RUN \
	    apt-get update  && \
	    apt-get install -y  redis-server
RUN \
	    pecl install -o  redis  && \
	    echo "extension=redis.so" >> /usr/local/etc/php/conf.d/redis.ini

# This should prevent the tests from running on a host with a real Redis instance.
ENV REDIS_PORT 16379

ADD docker-start.sh /start.sh
RUN chmod +x /start.sh
ENTRYPOINT ["/start.sh"]

USER nobody
VOLUME ["/mnt"]
WORKDIR /mnt
CMD ["vendor/bin/phpunit"]
