---
description: "Adding tenant headers, role checks, timing and audit logging around Ecotone handlers"
tags: [ecotone-interceptors]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building a Symfony app with Ecotone 2.0. These are some of my handlers:

```php
<?php

namespace App\Billing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;

final class InvoiceService
{
    #[RequiresRole('accountant')]
    #[Asynchronous('billing')]
    #[CommandHandler(endpointId: 'issueInvoice')]
    public function issue(IssueInvoice $command): void
    {
        // writes the invoice, returns nothing
    }

    #[Timed]
    #[CommandHandler]
    public function cancel(CancelInvoice $command): void
    {
    }

    #[CommandHandler]
    public function correct(CorrectInvoice $command): void
    {
    }
}
```

`RequiresRole` and `Timed` are my own PHP attributes (`#[Attribute(Attribute::TARGET_METHOD)]`, `RequiresRole` has a public `string $role`). Every command carries a `userRoles` header (array of strings).

I need, without touching the handler bodies:

1. A `tenantId` header added to every command, taken from my `TenantContext` service (`TenantContext::currentTenantId(): string`).
2. Commands on handlers marked `#[RequiresRole]` rejected with an `AccessDenied` exception when `userRoles` lacks the role. A rejected `IssueInvoice` must never reach the `billing` queue.
3. The `tenantId` header must already be set when the role check runs, because the check logs the tenant.
4. Execution time of handlers marked `#[Timed]` logged through my PSR logger.
5. An audit log line written after every command handler finishes successfully.

Write the interceptor classes with their attributes. Don't ask questions.
