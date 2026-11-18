---
description: "A framework interceptor that must know which message the process is currently handling"
tags: [framework-service-design]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm working in the Ecotone framework monorepo (ecotone-dev), in `packages/Ecotone/src`. I'm adding an audit trail: every message a handler sends while it handles a command should carry the id of that command in an `ecotone_audit_parent_id` header, also when the handler sends through the event bus two calls deep, where it has no access to the command message.

My plan is an around interceptor on the buses that remembers the id while the handler runs, and a second method the channel interceptor calls to read it:

```php
<?php

namespace Ecotone\Messaging\Audit;

class AuditParentInterceptor
{
    public function rememberParent(MethodInvocation $methodInvocation, Message $message): mixed
    {
    }

    public function currentParentId(): ?string
    {
    }
}
```

Handlers can send commands from inside handlers, so parents nest. Write the class in full, and tell me how I prove it works when our users run the application on Swoole with coroutines enabled. Answer with full code; don't ask me questions.
