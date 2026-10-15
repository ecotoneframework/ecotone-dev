<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant\Module;

use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Logger\LoggingService;

/**
 * licence Apache-2.0
 */
final class IncludeTenantHeaderInLogContext implements CompilerPass
{
    /**
     * @param array<int, string> $tenantHeaderNames
     */
    public function __construct(private array $tenantHeaderNames)
    {
    }

    public function process(ContainerBuilder $builder): void
    {
        if (! $builder->has(LoggingGateway::class)) {
            return;
        }

        $loggingGatewayDefinition = $builder->getDefinition(LoggingGateway::class);
        if (! is_a($loggingGatewayDefinition->getClassName(), LoggingService::class, true)) {
            return;
        }

        foreach ($this->tenantHeaderNames as $tenantHeaderName) {
            $loggingGatewayDefinition->addMethodCall('includeHeaderInLogContext', [$tenantHeaderName]);
        }
    }
}
