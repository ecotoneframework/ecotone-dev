---
type: llm
---
PASS if the orders `DistributedServiceMap` uses `withCommandMapping('<inventory name>', '<channel>')`, that channel is registered as a RabbitMQ channel (e.g. `AmqpBackedMessageChannelBuilder::create('<channel>')`) in orders and in inventory, and inventory is told to consume it with `bin/console ecotone:run <channel>`. FAIL if the mapped channel is not registered in the sending service, the receiving service never registers or consumes that channel, or the channel names differ between the two services.
