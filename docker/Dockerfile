# Test stand only: WordPress on a chosen PHP version, with WP-CLI and Composer.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-apache

RUN docker-php-ext-install mysqli \
	&& curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
	&& chmod +x /usr/local/bin/wp \
	&& echo "memory_limit=512M" > /usr/local/etc/php/conf.d/stand.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
