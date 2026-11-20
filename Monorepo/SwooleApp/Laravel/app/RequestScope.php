<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Laravel\app;

use Ecotone\Api\Attribute\QueryHandler;
use Illuminate\Http\Request;

final class RequestScope
{
    public function __construct(private Request $injectedRequest)
    {
    }

    #[QueryHandler('laravel.requestScope')]
    public function user(): array
    {
        return [
            'user_from_constructor_injected_request' => $this->injectedRequest->header('X-User'),
            'user_from_request_helper' => request()->header('X-User'),
            'handler_object' => spl_object_id($this),
        ];
    }
}
