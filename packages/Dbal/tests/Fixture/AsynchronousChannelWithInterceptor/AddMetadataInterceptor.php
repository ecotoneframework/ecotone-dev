<?php

namespace Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor;

use Ecotone\Messaging\Attribute\Interceptor\Before;

final class AddMetadataInterceptor
{
    public const SAFE_ORDER = 'safeOrder';

    #[Before(pointcut: '*', changeHeaders: true)]
    public function addMetadata(): array
    {
        return [
            self::SAFE_ORDER => true,
        ];
    }
}
