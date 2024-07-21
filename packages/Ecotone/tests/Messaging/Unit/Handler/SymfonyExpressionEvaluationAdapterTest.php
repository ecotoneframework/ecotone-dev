<?php

namespace Test\Ecotone\Messaging\Unit\Handler;

use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\SymfonyExpressionEvaluationAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Class SymfonyExpressionEvaluationAdapterTest
 * @package Test\Ecotone\Messaging\Unit\Handler
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class SymfonyExpressionEvaluationAdapterTest extends TestCase
{
    public function test_is_array_function()
    {
        $expressionLanguage = SymfonyExpressionEvaluationAdapter::create(InMemoryReferenceSearchService::createEmpty());

        $this->assertTrue($expressionLanguage->evaluate('isArray([])', [], InMemoryReferenceSearchService::createEmpty()));
        $this->assertFalse($expressionLanguage->evaluate("isArray('some')", [], InMemoryReferenceSearchService::createEmpty()));
    }

    public function test_do_for_each_element_in_array()
    {
        $expressionLanguage = SymfonyExpressionEvaluationAdapter::create(InMemoryReferenceSearchService::createEmpty());

        $this->assertEquals(
            [
                [
                    'id' => 1,
                ],
                [
                    'id' => 2,
                ],
                [
                    'id' => 3,
                ],
            ],
            $expressionLanguage->evaluate("each(payload, 'createArray(\'id\', element)')", ['payload' => [1, 2, 3]], InMemoryReferenceSearchService::createEmpty())
        );
    }
}
