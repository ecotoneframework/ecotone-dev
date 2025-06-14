#!/bin/bash

set -e

php artisan optimize:clear
php artisan app:example-command John --types=1 --types=2 --selection=custom