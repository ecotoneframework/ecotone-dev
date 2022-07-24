#!/usr/bin/env bash

docker run -v $HOME/.gitconfig:/root/.gitconfig -v $PWD:/data/app --workdir=/data/app --entrypoint vendor/bin/monorepo-builder simplycodedsoftware/php:8.1 release "$1"