<?php

declare(strict_types=1);

namespace Test\EnvPlaceholderKafka;

use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Gateway\MessagePublisher;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Symfony\App\EnvPlaceholderKafka\Configuration\Kernel;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/**
 * Reproduces https://github.com/ecotoneframework/ecotone-dev/issues/669
 *
 * Path B: the #[KafkaConsumer] attribute is serialized as an endpoint annotation
 * (AnnotatedMethod::getAllAnnotationDefinitions -> AttributeDefinition::fromObject)
 * and embedded into the consumer's runtime definition once the DBAL error-handling
 * path is active. Symfony then rewrites the %env()% placeholder inside the serialized
 * blob, breaking its length prefix, so the consumer can never be built and the
 * published Message is never consumed.
 *
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class EnvPlaceholderKafkaConsumerTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('SYMFONY_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);
        putenv('ECOTONE_KAFKA_SUFFIX=it' . substr(Uuid::v4()->toRfc4122(), 0, 8));

        (new Filesystem())->remove([__DIR__ . '/var', '/tmp/ecotone']);
    }

    protected function tearDown(): void
    {
        putenv('SYMFONY_LICENCE_KEY');
        putenv('ECOTONE_KAFKA_SUFFIX');

        restore_exception_handler();
    }

    public function test_publishing_and_consuming_from_topic_built_from_env_placeholder(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $container = $kernel->getContainer();

        $payload = Uuid::v7()->toRfc4122();
        $container->get(MessagePublisher::class)->send($payload);

        $container->get(ConfiguredMessagingSystem::class)
            ->run('ordersKafkaConsumer', ExecutionPollingMetadata::createWithTestingSetup(
                executionTimeLimitInMilliseconds: 30000,
            ));

        $this->assertSame(
            [$payload],
            $container->get(QueryBus::class)->sendWithRouting('ordersKafka.consumedPayloads'),
        );
    }
}
