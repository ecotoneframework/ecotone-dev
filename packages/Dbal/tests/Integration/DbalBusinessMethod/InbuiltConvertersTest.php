<?php

declare(strict_types=1);

namespace Integration\DbalBusinessMethod;

use DateTimeImmutable;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\ActivityService;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\DateTimeToDayStringConverter;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonId;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class InbuiltConvertersTest extends DbalMessagingTestCase
{
    public function test_using_date_time_parameter_default_conversion()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var ActivityService $activityGateway */
        $activityGateway = $ecotoneLite->getGateway(ActivityService::class);
        $activityGateway->add('1', 'registered_at', new DateTimeImmutable('2020-01-01 10:00:00'));

        $this->assertEquals(
            [],
            $activityGateway->findAfterOrAt('registered_at', new DateTimeImmutable('2020-01-01 10:00:01'))
        );

        $this->assertEquals(
            ['1'],
            $activityGateway->findAfterOrAt('registered_at', new DateTimeImmutable('2020-01-01 10:00:00'))
        );
    }

    public function test_using_defined_converter_over_default_one()
    {
        $ecotoneLite = $this->bootstrapEcotone([
            DateTimeToDayStringConverter::class => new DateTimeToDayStringConverter(),
        ]);
        /** @var ActivityService $activityGateway */
        $activityGateway = $ecotoneLite->getGateway(ActivityService::class);
        /** This will be converted to 2020-01-02 00:00:00 */
        $activityGateway->add('1', 'registered_at', new DateTimeImmutable('2020-01-02 01:00:00'));

        $this->assertEquals(
            [],
            /** This will be converted to 2020-01-02 00:00:00 */
            $activityGateway->findBefore('registered_at', new DateTimeImmutable('2020-01-02 23:59:59'))
        );

        $this->assertEquals(
            ['1'],
            /** This will be converted to 2020-01-03 00:00:00 */
            $activityGateway->findBefore('registered_at', new DateTimeImmutable('2020-01-03 00:00:00'))
        );
    }

    public function test_using_to_string_method_for_object_conversion_if_available()
    {
        $ecotoneLite = $this->bootstrapEcotone();
        /** @var ActivityService $activityGateway */
        $activityGateway = $ecotoneLite->getGateway(ActivityService::class);
        $activityGateway->store(new PersonId('1'), 'registered_at', new DateTimeImmutable('2020-01-01 10:00:00'));

        $this->assertEquals(
            [],
            $activityGateway->findAfterOrAt('registered_at', new DateTimeImmutable('2020-01-01 10:00:01'))
        );

        $this->assertEquals(
            ['1'],
            $activityGateway->findAfterOrAt('registered_at', new DateTimeImmutable('2020-01-01 10:00:00'))
        );
    }

    private function bootstrapEcotone(array $services = []): FlowTestSupport
    {
        $this->setupActivityTable();

        return EcotoneLite::bootstrapFlowTesting(
            array_merge([ActivityService::class], array_keys($services)),
            array_merge(
                [
                    DbalConnectionFactory::class => DbalConnection::create($this->getConnection()),
                ],
                $services
            ),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false
        );
    }
}
