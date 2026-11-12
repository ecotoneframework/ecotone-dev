---
type: llm
---
PASS if the report applies the project's storage and transactions guidance (framework-storage-and-transactions) to the interceptor, although the diff touches no table manager, migration or storage skill file — for example by flagging the table created on the message path or the write made after the handler outside its transaction — and does not limit the skills it applies to the one skill file the diff edits (testing) or to agent-tooling. FAIL if storage and transactions guidance is not applied, or the report treats only the edited skill file as relevant.
