<?php

declare(strict_types=1);

namespace App\Security;

/**
 * RFC 6238 time-based one-time passwords (HMAC-SHA1, 30-second step, 6 digits —
 * the parameters every authenticator app defaults to). Pure functions of
 * (secret, time), so it is tested directly against the RFC's published vectors.
 */
final class Totp
{
    public const STEP_SECONDS = 30;
    public const DIGITS = 6;
    /** Accept the previous and next step too, to absorb clock drift between phone and server. */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh random secret, base32 (160 bits, the RFC's recommended size). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    /** @return string|null null if the input isn't valid base32 */
    public static function base32Decode(string $s): ?string
    {
        $s = strtoupper(str_replace([' ', '-', '='], '', $s));
        if ($s === '' || strspn($s, self::ALPHABET) !== strlen($s)) {
            return null;
        }
        $bits = '';
        foreach (str_split($s) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }

    public function codeAt(string $secretBase32, int $step, int $digits = self::DIGITS): string
    {
        $key = self::base32Decode($secretBase32) ?? throw new \InvalidArgumentException('Secret is not valid base32.');
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $bin = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public function stepAt(int $unixTime): int
    {
        return intdiv($unixTime, self::STEP_SECONDS);
    }

    /**
     * @param int|null $afterStep reject any step <= this (replay protection: the
     *        last step already accepted for this user)
     * @return int|null the time-step that matched, or null
     */
    public function verify(string $secretBase32, string $code, int $unixTime, ?int $afterStep = null): ?int
    {
        $code = str_replace(' ', '', trim($code));
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $current = $this->stepAt($unixTime);
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $current + $offset;
            if ($afterStep !== null && $step <= $afterStep) {
                continue;
            }
            if (hash_equals($this->codeAt($secretBase32, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** The otpauth:// URI authenticator apps read from a QR code. */
    public static function provisioningUri(string $secretBase32, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secretBase32,
            rawurlencode($issuer),
            self::DIGITS,
            self::STEP_SECONDS,
        );
    }
}
