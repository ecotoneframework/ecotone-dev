<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class SameOriginGuard
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (! $event->isMainRequest() || $request->isMethodSafe()) {
            return;
        }

        $refusal = $this->refusalFor($request);
        if ($refusal !== null) {
            $event->setResponse(new Response($refusal, Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain; charset=UTF-8']));
        }
    }

    private function refusalFor(Request $request): ?string
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if ($fetchSite !== null && ! in_array($fetchSite, ['same-origin', 'none'], true)) {
            return sprintf('Refused: the browser marked this request as coming from "%s" through Sec-Fetch-Site, and Pulse only accepts changes made from its own pages. Open Pulse and use its buttons, or call it without browser headers from a script.', $fetchSite);
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null && $this->hostOf($origin, $request) !== $request->getHttpHost()) {
            return sprintf('Refused: the Origin header "%s" differs from the host Pulse is served on ("%s"), and Pulse only accepts changes made from its own pages. Open Pulse and use its buttons, or call it without browser headers from a script.', $origin, $request->getHttpHost());
        }

        return null;
    }

    private function hostOf(string $origin, Request $request): string
    {
        $parts = parse_url($origin);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return $origin;
        }

        $port = $parts['port'] ?? null;
        $defaultPort = ($parts['scheme'] ?? $request->getScheme()) === 'https' ? 443 : 80;

        return $port === null || $port === $defaultPort ? $parts['host'] : $parts['host'] . ':' . $port;
    }
}
