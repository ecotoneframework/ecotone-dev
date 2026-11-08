---
type: llm
---
PASS if `Invoice` keeps `private string $internalNumber` without `#[Identifier]` and exposes it through a public method marked `#[IdentifierMethod('invoiceId')]`, and `ApproveInvoice` carries a property named `invoiceId`. FAIL if the property is renamed to `invoiceId`, if `#[Identifier]` is put on `$internalNumber` with `ApproveInvoice` mapped to `internalNumber`, or if `#[IdentifierMethod]` is missing.
