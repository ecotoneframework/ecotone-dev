---
description: "Adding a logger, clock, counter and attribute metadata to a framework service"
tags: [framework-service-design]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm working in the Ecotone framework monorepo (ecotone-dev), in `packages/Ecotone/src`. This service is registered in the container by a module:

```php
<?php

namespace Ecotone\Messaging\Handler\Processor;

class MessageAuditor
{
    public function audit(Message $message): void
    {
    }
}
```

I need it to (a) log through an optional PSR logger when the application provides one, (b) use the clock to stamp audits, (c) remember how many messages it audited so I can expose that count, and (d) read the handler's method attributes so it can skip handlers marked `#[NoAudit]`. Write the new version of the class and tell me how the module should register it. Answer with full code; don't ask me questions.
