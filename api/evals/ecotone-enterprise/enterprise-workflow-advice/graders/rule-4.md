---
type: llm
---
PASS if the answer shows the licence key set in `config/ecotone.php` as `'licenceKey' => env('ECOTONE_LICENCE_KEY')` (or another environment variable), or equivalently reads it from the environment. FAIL if no licence key configuration is shown, it is placed under an invented config key, or the key is hard-coded as a literal in a committed file.
