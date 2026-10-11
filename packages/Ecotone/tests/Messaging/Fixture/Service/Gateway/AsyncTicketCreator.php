<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Service\Gateway;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\MessageGateway;

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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
interface AsyncTicketCreator
{
    #[Asynchronous('async')]
    #[MessageGateway('create')]
    public function create($data);

    #[Asynchronous('async')]
    #[MessageGateway('proxy')]
    public function proxy($data);
}
