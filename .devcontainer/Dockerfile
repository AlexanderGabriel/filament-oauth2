ARG PHP_VERSION=8.4
FROM mcr.microsoft.com/devcontainers/php:1-${PHP_VERSION}-bookworm

# Same extensions as the CI workflow (.github/workflows/run-tests.yml)
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
# The base image ships a Yarn apt source with an expired key, which breaks apt-get update
RUN rm -f /etc/apt/sources.list.d/yarn.list \
    && install-php-extensions bcmath intl pcntl zip pdo_sqlite

# CI runs without a coverage driver. With Xdebug loaded, PHPUnit tries to write the
# <coverage> reports from phpunit.xml.dist and fails. Re-enable it on demand with:
#   mv /usr/local/etc/php/conf.d/xdebug.ini.disabled /usr/local/etc/php/conf.d/xdebug.ini
RUN mv /usr/local/etc/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini.disabled
