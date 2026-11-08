---
type: llm
---
PASS if the answer says it will check names against the current source tree (searching packages/, or upgrade/namespace-map-2.0.csv) and identifies the 2.0 namespace Ecotone\Api\Attribute\CommandHandler as current, or at least does not present a 1.x namespace such as Ecotone\Modelling\Attribute\CommandHandler as current. FAIL if it presents the 1.x namespace as the one to use or relies on memory alone.
