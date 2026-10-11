<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Laravel\LaravelConnectionReference;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class LaravelConnectionWithoutDbalTest extends TestCase
{
    protected function setUp(): void
    {
        if (class_exists('Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference')) {
            self::markTestSkipped('ecotone/dbal is installed in this run, so its absence cannot be observed here; packages/Laravel runs this on its own vendor, which does not install it');
        }
    }

    public function test_a_laravel_connection_reference_is_refused_at_bootstrap_when_ecotone_dbal_is_not_installed(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::refusalFor("LaravelConnectionReference::create('reporting')"));

        EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::LARAVEL_PACKAGE])
                ->withExtensionObjects([LaravelConnectionReference::create('reporting')]),
        );
    }

    public function test_the_default_laravel_connection_is_refused_at_bootstrap_when_ecotone_dbal_is_not_installed(): void
    {
        $configuration = new class () {
            #[ServiceContext]
            public function connection(): LaravelConnectionReference
            {
                return LaravelConnectionReference::defaultConnection('mysql');
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::refusalFor("LaravelConnectionReference::defaultConnection('mysql')"));

        EcotoneLite::bootstrapFlowTesting(
            [$configuration::class],
            [$configuration],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::LARAVEL_PACKAGE]),
        );
    }

    private static function refusalFor(string $connectionReference): string
    {
        return "{$connectionReference} hands a Laravel database connection to Ecotone, but ecotone/dbal is not installed. "
            . 'Only the Database capabilities ecotone/dbal brings read a connection reference — DBAL transactions, the Outbox, the Dead Letter, the Document Store, multi-tenant connections and the event store — so without it the connection has nothing to serve. '
            . 'Either install it with `composer require ecotone/dbal`, '
            . 'or remove the LaravelConnectionReference from your #[ServiceContext] configuration.';
    }
}
