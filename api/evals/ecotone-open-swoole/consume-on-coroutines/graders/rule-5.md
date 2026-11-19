---
type: llm
---
PASS if the answer switches RabbitMQ to `php-amqplib` for the coroutine consumer: `Ecotone\Api\Amqp\AmqpLibConnectionFactory` in place of `Ecotone\Api\Amqp\AmqpExtConnectionFactory`, with the reason that the `amqp` extension blocks every coroutine. FAIL if `AmqpExtConnectionFactory` (the `amqp` extension) is kept for the coroutine consumer without that warning.
