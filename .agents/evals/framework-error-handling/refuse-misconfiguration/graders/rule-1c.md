---
type: llm
---
PASS if the placement/signature checks are done at compile time in the module's prepare() (or a guard it calls) using InterfaceToCallRegistry / annotation metadata, leaving only the message-content check (c) to runtime. FAIL if (a) is checked at runtime.
