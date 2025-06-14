#!/bin/bash

set -e

bin/console cache:clear
php bin/console app:example-command John --types=1 --types=2 --selection=custom