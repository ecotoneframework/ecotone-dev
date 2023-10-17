<?php

declare(strict_types=1);

namespace App\ReactiveSystem;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface OrderController
{
    public function placeOrder(Request $request): Response;
}