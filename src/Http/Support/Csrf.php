<?php

declare(strict_types=1);

namespace App\Http\Support;

/**
 * Synchroniser-token CSRF protection for the server-rendered console
 * (brief §7). The API itself is stateless JWT and is not CSRF-exposed, so this
 * is only wired onto console routes (Phase 3).
 *
 * A single per-session secret is issued once and compared with hash_equals on
 * every state-changing console request (token sent via a hidden form field or
 * the `X-CSRF-Token` header).
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf';

    public function __construct(private readonly SessionStore $session)
    {
    }

    /** Current token, creating one on first use. */
    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function isValid(?string $candidate): bool
    {
        $token = $this->session->get(self::SESSION_KEY);

        return is_string($token)
            && $token !== ''
            && is_string($candidate)
            && hash_equals($token, $candidate);
    }

    /** Rotate the token — call right after a privilege change (e.g. login). */
    public function rotate(): string
    {
        $this->session->remove(self::SESSION_KEY);

        return $this->token();
    }
}
