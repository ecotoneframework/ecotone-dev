---
type: llm
---
PASS if handler attribute metadata comes from InterfaceToCall / InterfaceToCallRegistry (e.g. an InterfaceToCallReference resolved at container build), not new ReflectionClass/ReflectionMethod at runtime. FAIL if PHP reflection is used directly to read #[NoAudit].
