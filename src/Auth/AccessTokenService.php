<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\SystemClock;
use DateTimeImmutable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\Validator;
use Ramsey\Uuid\Uuid;

/**
 * Stateless access tokens (JWT, HS256 — decision Q5).
 *
 * Short-lived (default 15 min). The API trusts a valid signature + issuer +
 * expiry and does NOT hit the database on every request; revocation within
 * the 15-minute window is intentionally not supported (that is what the short
 * lifetime buys). Longer-lived trust is the refresh token's job.
 *
 * Claims: iss, iat, exp, jti, sub = user uuid, plus role and zone.
 */
final class AccessTokenService
{
    private Sha256 $signer;
    private InMemory $key;

    public function __construct(
        string $secret,
        private readonly string $issuer,
        private readonly int $ttlSeconds,
    ) {
        $this->signer = new Sha256();
        $this->key = InMemory::plainText($secret);
    }

    /**
     * @return array{token:string, expires_at:string, expires_in:int}
     */
    public function issue(string $userUuid, string $role, ?string $zone): array
    {
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify("+{$this->ttlSeconds} seconds");

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedBy($this->issuer)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)   // nbf — required by StrictValidAt
            ->expiresAt($expiresAt)
            ->identifiedBy(Uuid::uuid4()->toString())
            ->relatedTo($userUuid)
            ->withClaim('role', $role)
            ->withClaim('zone', $zone)
            ->getToken($this->signer, $this->key);

        return [
            'token'      => $token->toString(),
            'expires_at' => $expiresAt->format(DATE_ATOM),
            'expires_in' => $this->ttlSeconds,
        ];
    }

    /**
     * Parse and fully validate a token string.
     *
     * @throws InvalidTokenException on any malformation, bad signature, wrong
     *         issuer, or expiry.
     * @return array{uuid:string, role:string, zone:?string, jti:string}
     */
    public function verify(string $jwt): array
    {
        try {
            /** @var UnencryptedToken $token */
            $token = (new Parser(new JoseEncoder()))->parse($jwt);
        } catch (\Throwable $e) {
            throw new InvalidTokenException('Malformed access token.', previous: $e);
        }

        try {
            (new Validator())->assert(
                $token,
                new SignedWith($this->signer, $this->key),   // rejects tampering and alg:none
                new IssuedBy($this->issuer),                  // rejects tokens from elsewhere
                new StrictValidAt(new SystemClock()),    // rejects expired / not-yet-valid, no leeway
            );
        } catch (\Throwable $e) {
            throw new InvalidTokenException('Access token failed validation.', previous: $e);
        }

        $claims = $token->claims();

        return [
            'uuid' => (string) $claims->get('sub'),
            'role' => (string) $claims->get('role'),
            'zone' => $claims->get('zone') !== null ? (string) $claims->get('zone') : null,
            'jti'  => (string) $claims->get('jti'),
        ];
    }
}
