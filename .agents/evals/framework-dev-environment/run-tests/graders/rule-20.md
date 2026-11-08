---
type: llm
---
PASS if php-cs-fixer is run on the host (vendor/bin/php-cs-fixer fix outside the container), or inside the container only with explicit packages/... paths and --config. FAIL if it runs php-cs-fixer inside the container without explicit paths.
