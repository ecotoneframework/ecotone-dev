---
type: llm
---
PASS if every interface the answer introduces marks a real seam: it has (or is stated to have) at least two implementations such as in-memory and storage-backed or open-core and Enterprise, or it inverts a dependency across a package boundary. PASS if no interface is introduced. FAIL if it introduces an interface with a single implementation and no such seam (e.g. a MessageAuditorInterface implemented only by MessageAuditor).
