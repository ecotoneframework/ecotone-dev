---
type: llm
---
PASS if the single-test command includes --no-coverage and the package-suite command installs the package's own dependencies (cd packages/Dbal && composer install) before composer tests:ci. FAIL if --no-coverage is missing from the single-test command or the package suite is run without its own install.
