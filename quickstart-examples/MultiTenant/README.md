# Multi Tenant

This provides set of examples how to use Ecotone in multi tenant environment.

Each Module has it's own directory containing specific example and `run_example.php` file.  
To run example, execute `php run_example.php` in specific directory.  
Look at logs to see which channels are used and how messages are processed.  

## Command Handler for Multiple Tenants

[This example](src/CommandHandlerForMultipleTenants) shows how to use Command Handler for Multiple Tenants.

## Round Robin with Single Consumer

[This example](src/RoundRobinWithSingleConsumer) shows how to use Round Robin with Single Consumer.   
This is useful when we have multiple queues and we want to limit amount of Message Consumer processes we run.

## Outbox for Multiple Tenants

[This example](src/OutboxForMultipleTenants) shows how to use Outbox for Multiple Tenants.  
In case of using [Outbox pattern](https://docs.ecotone.tech/modelling/resiliency/resilient-sending#outbox-pattern) in multi-tenant environment, we will have separate outbox table per tenant.  
Considering scenario where each Tenant has separate instance of Application with it's own database deployed, we would to have Message Consumer per Tenant.    
With big volume of Tenants, this could lead to having a lot of Message Consumers running.  
To keep limited amount of Message Consumers running, we could use [Round Robin solution](src/RoundRobinWithSingleConsumer).  
However that could require scaling up Message Consumer fetching from Database to process to handle all tenants, which can affect the performance.    
To avoid that we could use [Combined Message Channel](https://docs.ecotone.tech/modelling/resiliency/resilient-sending#scaling-the-solution) in which single Message Consumer would fetch the Message and push it other Message Channel like RabbitMQ.    
This way Message Consumer fetching from database stays fast as it would only need to fetch and send Message to another Channel, and all the processing would be done by other RabbitMQ Message Consumer.  

* Depending on needs this solution could be combined with Dynamic Channels to allow dynamically allocating Message to ensure fair processing (one tenant would not block other tenants by producing much more messages than others).