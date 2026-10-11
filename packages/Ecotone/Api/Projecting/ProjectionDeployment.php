<?php

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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Ecotone\Api\Projecting;

use Attribute;

/**
 * Configure projection deployment settings for blue/green deployment scenarios.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ProjectionDeployment
{
    public function __construct(
        /**
         * When true, projection will not be automatically initialized.
         * It will require manual trigger
         *
         * Default: false (automatic initialization)
         */
        public readonly bool $manualKickOff = false,
        /**
         * When false, emitted events via EventStreamEmitter will not be published.
         * Use this for blue/green deployment to rebuild projection without
         * re-emitting events to downstream consumers.
         *
         * Default: true (events are emitted)
         */
        public readonly bool $live = true,
    ) {
    }
}
