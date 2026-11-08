---
type: llm
---
PASS if installation is `composer require ecotone/symfony-bundle` (plus `ecotone/dbal` for the database features) and `config/bundles.php` enables `Ecotone\SymfonyBundle\EcotoneSymfonyBundle::class` (or the answer notes Flex adds that line). FAIL if a different package or bundle class name is used.
