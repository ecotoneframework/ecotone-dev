#!/usr/bin/env bash

docker run -u 1000:1000 -v $HOME/.gitconfig:/root/.gitconfig -v $PWD:/data/app -v $HOME/.ssh:/root/.ssh --workdir=/data/app --entrypoint vendor/bin/monorepo-builder simplycodedsoftware/php:8.1 release "$1"