# Microservices PHP Demo

This provide demo of advanced integration of two Microservices - `Customer Service` and `Backoffice Service`.
State and events are stored in `Postgres` database.  
Integration of microservices happens over `RabbitMQ`.

## Customer Service

Provides possibility to report issues by customers.  
Customer can close the issue, if he thinks it's resolved.

- Implements [state-stored aggregate](https://docs.ecotone.tech/modelling/command-handling/state-stored-aggregate) `Issue` 
- Make of [Document Store](https://docs.ecotone.tech/modelling/document-store) as Issue Repository
- Sends [distributed commands](https://docs.ecotone.tech/modelling/microservices-php#commands) to `BackofficeService` as a result of event happening in Issue aggregate
- Make use of asynchronous channel backed by database in order to support [Outbox Pattern](https://docs.ecotone.tech/modelling/asynchronous-handling#outbox-pattern).

## Backoffice Service

Provides ticket system for internal employees to work on given issue.  
Ticket API is exposed over distributed command handlers

- Implements [event-sourced aggregate](https://docs.ecotone.tech/modelling/event-sourcing) `Ticket`
- Builds [projection](https://docs.ecotone.tech/modelling/event-sourcing/setting-up-projections) based on events from `Ticket` aggregate and stores the state in database
- Receives [distributed commands](https://docs.ecotone.tech/modelling/microservices-php#commands) from `CustomerService`

# Ecotone with Laravel and Symfony

`Ecotone` does integrate with [Laravel](https://docs.ecotone.tech/modules/laravel-ddd-cqrs-event-sourcing) and [Symfony](https://docs.ecotone.tech/modules/symfony-ddd-cqrs-event-sourcing).    
So the code used in the demo, could also be used with given frameworks.

# Demo with UI for Laravel and Symfony

If you want to play with Demo having UI written in Symfony and Laravel, follow [this link](https://github.com/ecotoneframework/php-ddd-cqrs-event-sourcing-symfony-laravel-ecotone). 

# Insights

The main focus in on the domain model. `Ecotone` solves all integration problems, so the `amount of the code to build and integrate services is minimal`.