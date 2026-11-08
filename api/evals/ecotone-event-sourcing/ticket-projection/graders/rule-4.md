---
type: llm
---
PASS if old events are handled in the code that reads them, e.g. the projection handler reads `#[Header(MessageHeaders::REVISION)]` or checks the missing priority and falls back to `normal`, and no stored event is migrated, rewritten or updated in SQL and no custom upcaster class is introduced. If a header parameter is added to an aggregate `#[Apply]` method, the answer must say this requires Ecotone Enterprise. FAIL if the answer proposes rewriting stored events or an upcaster, or adds a header parameter to `#[Apply]` without stating it is Enterprise.
