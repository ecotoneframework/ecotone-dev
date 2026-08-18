<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Attribute;

use Ecotone\Kafka\Attribute\KafkaConsumer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\Expression;
use TypeError;

/**
 * licence Enterprise
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
