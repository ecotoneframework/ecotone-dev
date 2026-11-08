---
type: llm
---
PASS if installation is `composer require ecotone/laravel` (plus `ecotone/dbal` for the database features) and the configuration is published with `php artisan vendor:publish --tag=ecotone-config`, without asking to register a service provider by hand. FAIL if the answer installs a different package name, tells the user to add `EcotoneProvider` to `config/app.php` or `bootstrap/providers.php` as a required step, or never shows how to get `config/ecotone.php`.
