FROM wordpress

RUN pecl install xdebug
RUN echo "zend_extension=xdebug.so" > /usr/local/etc/php/conf.d/xdebug.ini
RUN echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/xdebug.ini
RUN echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/xdebug.ini
RUN echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/xdebug.ini

RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
RUN chmod +x wp-cli.phar
RUN mv wp-cli.phar /usr/local/bin/wp