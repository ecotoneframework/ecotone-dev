<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Support\LicensingException;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DecisionModelLicenceTest extends TestCase
{
    public function test_bootstrap_throws_licensing_exception_when_decision_model_used_without_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [ModelForLicenceTest::class, TaggedEventForDecisionModelLicenceTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
        );
    }
}

final readonly class TaggedEventForDecisionModelLicenceTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
    ) {
    }
}

#[DecisionModel]
final class ModelForLicenceTest
{
    #[EventSourcingHandler]
    public function when(TaggedEventForDecisionModelLicenceTest $event): void
    {
    }
}
