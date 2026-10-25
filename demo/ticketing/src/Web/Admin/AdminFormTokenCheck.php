<?php

declare(strict_types=1);

namespace App\Web\Admin;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[AsEventListener]
final class AdminFormTokenCheck
{
    private const TOKEN_ID = 'admin';

    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (! $event->isMainRequest() || ! $request->isMethod('POST') || ! str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }

        if (! $this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, $request->getPayload()->getString('_token')))) {
            throw new AccessDeniedHttpException(sprintf('The admin form posted to %s carries no valid CSRF token, so nothing was changed. Every admin form renders its token in the "_token" field: reload the page and submit the form from it again.', $request->getPathInfo()));
        }
    }
}
