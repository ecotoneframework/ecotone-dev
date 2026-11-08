---
type: llm
---
PASS if the Enterprise behaviour is a separate class/file with a `licence Enterprise` header, the open-source file keeps `licence Apache-2.0` and is unchanged (no if-licensed branch inside it), and the choice is made in the container via LicenceDecider (or equivalent container-level selection). FAIL if it adds an `if ($hasLicence)` branch in the open-source class.
