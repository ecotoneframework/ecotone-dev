---
type: llm
---
PASS if cases (a) and (b) are refused while the application boots (bootstrap/container build, ConfigurationException), not on the first message, and nothing is silently ignored. FAIL if either is detected only at runtime or silently picks one configuration.
