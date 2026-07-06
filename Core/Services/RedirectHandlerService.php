<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\Session\SessionInterface;

final class RedirectHandlerService
{
    private const string INTENDED_URL_KEY = 'redirect.intended_url';

    public function __construct(
        private readonly SessionInterface $session
    ) {
    }

    private function ensureSession(): void
    {
        if (!$this->session->isStarted()) {
            $this->session->start();
        }
    }

    /**
     * Store a URL to return to after the current task is completed.
     */
    public function setIntendedUrl(string $url): void
    {
        $this->ensureSession();
        $this->session->set(self::INTENDED_URL_KEY, $url);
    }

    /**
     * Get the intended URL if it exists, otherwise return a fallback.
     * Use this in your controller to decide where to send the user.
     */
    public function getRedirect(string $fallbackUrl): string
    {
        $this->ensureSession();

        $intended = $this->session->get(self::INTENDED_URL_KEY);

        if ($intended) {
            $this->session->remove(self::INTENDED_URL_KEY);
            return $intended;
        }

        return $fallbackUrl;
    }
}
