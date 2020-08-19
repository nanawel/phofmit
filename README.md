Phofmit: PHP Offline Folder Mirroring Tool
===

## Requirements

- PHP 7.2+ or Docker

## Usage (local)

```
composer install

bin/console phofmit:snapshot ...
bin/console phofmit:mirror ...
```

## Usage (from Docker)

> Using [`thecodingmachine/php:7.3-v3-cli`](https://github.com/thecodingmachine/docker-images-php)
> as base image.

```
docker run -it \
    --rm -v $(pwd):/usr/src/app \
    -v /tmp:/mnt/tmp \
    -v /my/target/folder:/mnt/target \
    -v /usr/local/bin/composer:/usr/local/bin/composer \
    -v ~/.composer/auth.json:/root/.composer/auth.json \
    thecodingmachine/php:7.3-v3-cli \
    /bin/bash

composer install

bin/console phofmit:snapshot ...
bin/console phofmit:mirror ...
```
