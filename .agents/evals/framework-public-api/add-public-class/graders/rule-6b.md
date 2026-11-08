---
type: llm
---
PASS if the configuration class uses promoted public readonly properties and with*() methods that return a new instance, with no plain getters and no writable properties. FAIL if it has getX() getters that only return a property, setters, or mutable properties.
