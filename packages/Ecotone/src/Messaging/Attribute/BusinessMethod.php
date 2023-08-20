<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Attribute;

use Attribute;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;

#[Attribute(Attribute::TARGET_METHOD)]
class BusinessMethod extends MessageGateway
{

}
