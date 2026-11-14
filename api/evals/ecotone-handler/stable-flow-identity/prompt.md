---
description: "Keep a synchronous handler recognizable in saved flow comparisons after a class and method rename"
tags: [ecotone-handler]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building an Ecotone 2.0 application. My synchronous `OrderService::place` handles this command:

```php
final class PlaceOrder
{
    public function __construct(public readonly string $orderId) {}
}
```

Before storing the first version of the application's message flow, show the handler code and choose its
attributes. In the next release I will rename the class to `OrderPlacement` and the method to `submit`, while
keeping the command and its routing the same. I want the saved-flow comparison to recognize the same handler.
Show the renamed handler too and explain which nodes and arrows the comparison reports as changed, added or
removed. Don't ask questions.
