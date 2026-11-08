---
type: llm
---
PASS if the webhook controller calls `sendWithRouting('order.markAsPaid', [...], metadata: ['aggregate.id' => $orderId])` (passing the id under the `aggregate.id` metadata key) and the `Order` handler is `#[CommandHandler('order.markAsPaid')]` taking the array, without any identifier mapping. FAIL if the order id is put in the array payload and mapped with `identifierMapping`, if a command class is created, or if the controller loads the aggregate from a repository.
