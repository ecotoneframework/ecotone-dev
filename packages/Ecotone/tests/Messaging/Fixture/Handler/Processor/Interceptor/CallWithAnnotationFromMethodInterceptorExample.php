<?php

namespace Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor;

use Ecotone\Api\Around;
use Ecotone\Api\InternalHandler;

/**
 * Class CallWithAnnotationFromMethodInterceptorExample
 * @package Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class CallWithAnnotationFromMethodInterceptorExample extends BaseInterceptorExample
{
    #[Around]
    public function callWithMethodAnnotation(InternalHandler $methodAnnotation): void
    {
    }
}
