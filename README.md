<p align="left"><a href="https://ecotone.tech" target="_blank">
    <img src="https://github.com/ecotoneframework/ecotone-dev/blob/main/ecotone_small.png?raw=true">
</a></p>

![Github Actions](https://github.com/ecotoneFramework/ecotone-dev/actions/workflows/split-testing.yml/badge.svg)
[![Latest Stable Version](https://poser.pugx.org/ecotone/ecotone/v/stable)](https://packagist.org/packages/ecotone/ecotone)
[![License](http://poser.pugx.org/ecotone/ecotone/license)](https://packagist.org/packages/ecotone/ecotone)
[![Total Downloads](http://poser.pugx.org/ecotone/ecotone/downloads)](https://packagist.org/packages/ecotone/ecotone)
[![PHP Version Require](http://poser.pugx.org/ecotone/ecotone/require/php)](https://packagist.org/packages/ecotone/ecotone)

> The term "Ecotone", in ecology means transition area between ecosystems, such as forest and grassland.  
The Ecotone Framework functions as transition area between your components, modules and services. It glues things together, yet respects the boundaries of each area.
 
Ecotone is `Service Bus` implementation, which makes it possible to build scalable, resilient and message driven applications in PHP.    
On top of that provides supports for `DDD`, `CQRS` and `Event Sourcing`.

> Ecotone can be used with [Symfony](https://docs.ecotone.tech/modules/symfony-ddd-cqrs-event-sourcing) and [Laravel](https://docs.ecotone.tech/modules/laravel-ddd-cqrs-event-sourcing) frameworks.

## Getting started

The quickstart [page](https://docs.ecotone.tech/quick-start) of the 
[reference guide](https://docs.ecotone.tech) provides a starting point for using Ecotone.  
Read more on the [Blog](https://blog.ecotone.tech).

# Development

Copy `.env.dist` to `.env` and start docker containers

```php
docker-compose up -d
```

To run tests for monorepo:

```php
docker exec -it ecotone_development composer tests:local
```

To run tests for given module
```php
docker exec -it -w=/data/app/packages/Dbal ecotone_development composer tests:ci
```

Clear environment
```php
docker-compose down
```

Debugging code with Xdebug.    
To have enabled Xdebug all the time, change line in your .env file to `XDEBUG_ENABLED="1"`
To enable xdebug conditionally for given test run:  
```php
docker exec -it ecotone_development xdebug vendor/bin/phpunit --filter test_calling_command_on_aggregate_and_receiving_aggregate_instance
```

## Feature requests and issue reporting

Use [issue tracking system](https://github.com/ecotoneframework/ecotone/issues) for new feature request and bugs. 
Please verify that it's not already reported by someone else.

## Contact

If you want to talk or ask question about Ecotone

- [**Twitter**](https://twitter.com/EcotonePHP)
- **ecotoneframework@gmail.com**
- [**Community Channel**](https://discord.gg/CctGMcrYnV)

## Support Ecotone

If you want to help building and improving Ecotone consider becoming a sponsor:

- [Sponsor Ecotone](https://github.com/sponsors/dgafka)
- [Contribute to Ecotone](https://github.com/ecotoneframework/ecotone-dev).

## Tags

PHP DDD CQRS Event Sourcing Symfony Laravel Service Bus
