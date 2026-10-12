<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Attribute;

use Ecotone\Api\Kafka\KafkaConsumer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\Expression;
use TypeError;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class KafkaConsumerUnsupportedTopicsSyntaxTest extends TestCase
{
    public function test_a_symfony_expression_object_is_not_accepted_as_topics(): void
    {
        $this->expectException(TypeError::class);

        new KafkaConsumer(
            endpointId: 'orders',
            topics: new Expression("reference('config').getOrderTopics()"),
        );
    }

    public function test_a_plain_percent_placeholder_is_used_literally_not_resolved(): void
    {
        $consumer = new KafkaConsumer(
            endpointId: 'orders',
            topics: '%app.multi_tenancy.kafka.orders.topics%',
        );

        $this->assertSame(
            ['%app.multi_tenancy.kafka.orders.topics%'],
            $consumer->getTopics(),
            "Dynamic topics use an expression string containing '(' (e.g. parameter('...') or reference('...')->method()), not Symfony's %...% container-parameter placeholder syntax.",
        );
    }
}
