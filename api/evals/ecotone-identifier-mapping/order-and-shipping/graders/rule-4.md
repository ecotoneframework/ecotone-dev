---
type: llm
---
PASS if the `CourierAssigned` handler takes the id from the header with `identifierMetadataMapping: ['orderId' => 'x-order-id']` (or `identifierMapping: ['orderId' => "headers['x-order-id']"]`), and no handler in the answer declares both `identifierMetadataMapping` and `identifierMapping`. FAIL if the header is read inside the handler to look the saga up manually, or any handler combines both mapping options.
