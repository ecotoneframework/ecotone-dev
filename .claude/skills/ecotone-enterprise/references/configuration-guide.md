# Enterprise Configuration Guide

How to configure the Ecotone Enterprise licence key in your project.

---

## Symfony Configuration

In `config/packages/ecotone.yaml`:

```yaml
ecotone:
    licenceKey: '%env(ECOTONE_LICENCE_KEY)%'
```

Set the environment variable:
```bash
ECOTONE_LICENCE_KEY=your-licence-key-here
```

## Laravel Configuration

In `config/ecotone.php`:

```php
return [
    'licenceKey' => env('ECOTONE_LICENCE_KEY'),
];
```

Set the environment variable in `.env`:
```
ECOTONE_LICENCE_KEY=your-licence-key-here
```

## EcotoneLite (Standalone)

```php
use Ecotone\Lite\EcotoneLiteApplication;

$application = EcotoneLiteApplication::bootstrap(
    licenceKey: 'your-licence-key-here',
);
```

## Testing with Enterprise Features

Use `LicenceTesting::VALID_LICENCE` for test environments:

```php
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\LicenceTesting;

$ecotoneLite = EcotoneLite::bootstrapForTesting(
    [OrderFulfillmentOrchestrator::class],
    licenceKey: LicenceTesting::VALID_LICENCE,
);
```

## Obtaining a Licence Key

1. **Free trial (7 days)**: Contact **support@simplycodedsoftware.com**
2. **Purchase**: Visit [ecotone.tech/pricing](https://ecotone.tech/pricing)
3. The licence key is provided after purchase or trial activation
4. No per-server or container restrictions -- one key covers all environments
