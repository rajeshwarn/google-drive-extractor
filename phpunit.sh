#!/bin/sh
./vendor/bin/phpcs --standard=psr2 -n --ignore=vendor --extensions=php .
./vendor/bin/phpunit
./vendor/bin/test-reporter
