---
type: llm
---
PASS if the answer switches RabbitMQ to `php-amqplib` for the coroutine consumer: `Enqueue\AmqpLib\AmqpConnectionFactory` (package `enqueue/amqp-lib`) in place of `Enqueue\AmqpExt\AmqpConnectionFactory`, with the reason that the `amqp` extension blocks every coroutine. FAIL if `Enqueue\AmqpExt\AmqpConnectionFactory` is kept for the coroutine consumer without that warning.
