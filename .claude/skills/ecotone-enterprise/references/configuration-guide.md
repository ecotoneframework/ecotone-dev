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
use Ecotone\Api\Lite\EcotoneLite;

$messagingSystem = EcotoneLite::bootstrap(
    licenceKey: 'your-licence-key-here',
);
```

## Testing with Enterprise Features

Pass your own licence key (or trial key) to the flow-test bootstrap, read from the test environment so it never
lands in source control:

```php
use Ecotone\Api\Lite\EcotoneLite;

$ecotoneLite = EcotoneLite::bootstrapFlowTesting(
    [OrderFulfillmentOrchestrator::class],
    licenceKey: $_ENV['ECOTONE_LICENCE_KEY'],
);
```

Set `ECOTONE_LICENCE_KEY` in `phpunit.xml` (`<env name="ECOTONE_LICENCE_KEY" value="..."/>`) or in the CI job's
secrets.

## Obtaining a Licence Key

1. **Free trial (28 days)**: Contact **support@simplycodedsoftware.com**
2. **Purchase**: Visit [ecotone.tech/pricing](https://ecotone.tech/pricing)
3. The licence key is provided after purchase or trial activation
4. No per-server or container restrictions -- one key covers all environments
