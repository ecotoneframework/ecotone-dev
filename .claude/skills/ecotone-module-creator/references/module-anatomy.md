# Module Anatomy Reference

## Package Template Module

The minimal module template (from the package template directory):

```php
namespace Ecotone\_PackageTemplate\Configuration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

#[ModuleAnnotation]
final class _PackageTemplateModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(
        AnnotationFinder $annotationRegistrationService,
        InterfaceToCallRegistry $interfaceToCallRegistry
    ): static {
        return new self();
    }

    public function prepare(
        Configuration $messagingConfiguration,
        array $extensionObjects,
        ModuleReferenceSearchService $moduleReferenceSearchService,
        InterfaceToCallRegistry $interfaceToCallRegistry
    ): void {
    }


    public function getModulePackageName(): string
    {
        return '_PackageTemplate';
    }
}
```

## ModulePackageList Constants

Source: `Ecotone\Api\ExtensionObject\ModulePackageList`

```php
final class ModulePackageList
{
    public const CORE_PACKAGE = 'core';
    public const AMQP_PACKAGE = 'amqp';
    public const DATA_PROTECTION_PACKAGE = 'dataProtection';
    public const DBAL_PACKAGE = 'dbal';
    public const REDIS_PACKAGE = 'redis';
    public const SQS_PACKAGE = 'sqs';
    public const KAFKA_PACKAGE = 'kafka';
    public const EVENT_SOURCING_PACKAGE = 'eventSourcing';
    public const JMS_CONVERTER_PACKAGE = 'jmsConverter';
    public const TRACING_PACKAGE = 'tracing';
    public const LARAVEL_PACKAGE = 'laravel';
    public const SYMFONY_PACKAGE = 'symfony';
    public const TEMPEST_PACKAGE = 'tempest';
    public const TEST_PACKAGE = 'test';

    public static function allPackages(): array { ... }
    public static function getModuleClassesForPackage(string $packageName): array { ... }
}
```

There is no `allPackagesExcept()` -- exclusion is done by the caller, e.g. `array_diff(ModulePackageList::allPackages(), $skippedPackages)`.

To register a new package:
1. Add constant: `public const MY_PACKAGE = 'myPackage';`
2. Add to `allPackages()` return array
3. Add match case in `getModuleClassesForPackage()`

## AnnotationModule Interface

Source: `Ecotone\Messaging\Config\Annotation\AnnotationModule`

```php
interface AnnotationModule
{
    public static function create(
        AnnotationFinder $annotationRegistrationService,
        InterfaceToCallRegistry $interfaceToCallRegistry
    ): static;

    public function prepare(
        Configuration $messagingConfiguration,
        array $extensionObjects,
        ModuleReferenceSearchService $moduleReferenceSearchService,
        InterfaceToCallRegistry $interfaceToCallRegistry
    ): void;

    public function getModulePackageName(): string;
}
```

## NoExternalConfigurationModule

Source: `Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule`

Base class for modules that don't accept external configuration. Provides empty implementations for configuration-related methods.

## Configuration Interface (Key Methods)

Source: `Ecotone\Messaging\Config\Configuration`

Used in `prepare()` to register components:

```php
interface Configuration
{
    // Register message handlers
    public function registerMessageHandler(MessageHandlerBuilder $handler): self;

    // Register message channels
    public function registerMessageChannel(MessageChannelBuilder $channel): self;

    // Register consumers
    public function registerConsumer(ChannelAdapterConsumerBuilder $consumer): self;

    // Register converters
    public function registerConverter(CompilableBuilder $converter): self;
}
```

There is no `registerServiceActivator()` -- a `#[ServiceActivator]`/`#[InternalHandler]` handler is built with
`ServiceActivatorBuilder` and registered the same way as any other handler, through `registerMessageHandler()`.

## AnnotationFinder Interface (Key Methods)

Source: `Ecotone\AnnotationFinder\AnnotationFinder`

Used in `create()` to scan for annotations:

```php
interface AnnotationFinder
{
    // Find classes annotated with a specific attribute
    public function findAnnotatedClasses(string $annotationClassName): array;

    // Find methods annotated with a specific attribute; returns AnnotatedMethod[]
    public function findAnnotatedMethods(string $methodAnnotationClassName): array;

    // Get a required class/method attribute instance
    public function getAttributeForClass(string $className, string $attributeClassName): object;

    // Get an optional class/method attribute instance, or null
    public function findAttributeForClass(string $className, string $attributeClassName): ?object;
}
```

## Package Directory Structure

```
packages/<YourPackage>/
├── src/
│   ├── Configuration/
│   │   └── MyPackageModule.php        # Main module class
│   ├── Attribute/
│   │   └── MyCustomAttribute.php      # Custom attributes
│   └── ...                            # Package-specific code
├── tests/
│   └── MyPackageTest.php
├── composer.json
└── phpstan.neon
```

### composer.json Template

```json
{
    "name": "ecotone/my-package",
    "license": ["Apache-2.0", "proprietary"],
    "type": "library",
    "autoload": {
        "psr-4": {
            "Ecotone\\MyPackage\\": "src"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Test\\Ecotone\\MyPackage\\": "tests"
        }
    },
    "require": {
        "php": "^8.2",
        "ecotone/ecotone": "self.version"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5|^11.0"
    },
    "scripts": {
        "tests:phpstan": "vendor/bin/phpstan",
        "tests:phpunit": ["vendor/bin/phpunit --no-coverage"],
        "tests:ci": ["@tests:phpstan", "@tests:phpunit"]
    }
}
```

## Module with External Configuration

```php
// Configuration class (user provides via #[ServiceContext])
class MyPackageConfiguration
{
    private bool $enableFeatureX = false;

    public static function createWithDefaults(): self
    {
        return new self();
    }

    public function withFeatureX(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->enableFeatureX = $enabled;
        return $clone;
    }

    public function isFeatureXEnabled(): bool
    {
        return $this->enableFeatureX;
    }
}

// Module class
#[ModuleAnnotation]
final class MyPackageModule implements AnnotationModule
{
    public static function create(
        AnnotationFinder $annotationRegistrationService,
        InterfaceToCallRegistry $interfaceToCallRegistry
    ): static {
        return new self();
    }

    public function prepare(
        Configuration $messagingConfiguration,
        array $extensionObjects,
        ModuleReferenceSearchService $moduleReferenceSearchService,
        InterfaceToCallRegistry $interfaceToCallRegistry
    ): void {
        $configs = ExtensionObjectResolver::resolve(
            MyPackageConfiguration::class,
            $extensionObjects
        );

        $config = $configs[0] ?? MyPackageConfiguration::createWithDefaults();

        if ($config->isFeatureXEnabled()) {
            // Register additional handlers for feature X
        }
    }


    public function getModulePackageName(): string
    {
        return 'myPackage';
    }
}

// User configuration
class AppConfig
{
    #[ServiceContext]
    public function myPackageConfig(): MyPackageConfiguration
    {
        return MyPackageConfiguration::createWithDefaults()
            ->withFeatureX(true);
    }
}
```
