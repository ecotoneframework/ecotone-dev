---
type: llm
---
PASS if a `#[ServiceContext]` method returns `LaravelQueueMessageChannelBuilder::create('orders', 'redis')` (or `create('orders')` with Redis as the default queue connection) and the worker is started with `php artisan ecotone:run orders`. FAIL if the channel name differs from `orders`, the channel is not configured at all, or the only worker command given is `php artisan queue:work`.
