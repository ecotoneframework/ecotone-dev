---
type: llm
---
PASS if the `#[BusinessMethod('order.notify')]` method sends the order id as the payload and marks the notification channel parameter with `#[Header('...')]` (or passes both inside a single payload object, or as a second `array` of headers), and the handler reads the channel with a matching `#[Header('...')]` (or from the payload object). FAIL if the gateway method has two plain scalar parameters with no `#[Header]`, so the second value is never sent.
