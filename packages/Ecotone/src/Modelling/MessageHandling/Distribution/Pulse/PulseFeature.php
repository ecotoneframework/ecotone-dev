<?php

declare(strict_types=1);

namespace Ecotone\Modelling\MessageHandling\Distribution\Pulse;

use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;

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
final class PulseFeature
{
    /**
     * @param array<string, string> $settings
     * @param list<string> $handlers
     */
    public function __construct(
        public readonly string $package,
        public readonly string $name,
        public readonly bool $enabled,
        public readonly array $settings,
        public readonly array $handlers,
        public readonly string $hint,
        public readonly string $configuration,
    ) {
    }

    /**
     * @param object[] $serviceExtensions
     */
    public static function isReportedFor(array $serviceExtensions): bool
    {
        return ExtensionObjectResolver::resolve(PulseConfiguration::class, $serviceExtensions) !== [];
    }

    /**
     * @return array{name: string, enabled: bool, settings: array<string, string>, handlers: list<string>, hint: string, configuration: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'enabled' => $this->enabled,
            'settings' => $this->settings,
            'handlers' => $this->handlers,
            'hint' => $this->hint,
            'configuration' => $this->configuration,
        ];
    }
}
