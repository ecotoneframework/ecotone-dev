---
type: llm
---
PASS if the class keeps no mutable per-message state: no counter or collection property changed in audit() and no static cache; counting is delegated elsewhere or explicitly declined because container services are stateless. FAIL if a property like `private int $count` is incremented in audit() or a static cache is used.
