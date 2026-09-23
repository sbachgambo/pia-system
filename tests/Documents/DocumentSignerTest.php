<?php

declare(strict_types=1);

namespace App\Tests\Documents;

use App\Documents\DocumentSigner;
use PHPUnit\Framework\TestCase;

final class DocumentSignerTest extends TestCase
{
    public function testSignProducesA64CharHexHashAndSignature(): void
    {
        $signer = new DocumentSigner('test-hmac-key');
        $signed = $signer->sign('hello world');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signed['content_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signed['hmac_signature']);
        self::assertSame(hash('sha256', 'hello world'), $signed['content_hash']);
    }

    public function testVerifyAcceptsAGenuineDocument(): void
    {
        $signer = new DocumentSigner('test-hmac-key');
        $signed = $signer->sign('the pdf bytes');

        self::assertTrue($signer->verify('the pdf bytes', $signed['content_hash'], $signed['hmac_signature']));
    }

    public function testVerifyRejectsTamperedBytes(): void
    {
        $signer = new DocumentSigner('test-hmac-key');
        $signed = $signer->sign('the pdf bytes');

        self::assertFalse($signer->verify('the pdf bytes, but modified', $signed['content_hash'], $signed['hmac_signature']));
    }

    public function testVerifyRejectsAWrongSignatureEvenWithTheRightHash(): void
    {
        $signer = new DocumentSigner('test-hmac-key');
        $signed = $signer->sign('the pdf bytes');

        self::assertFalse($signer->verify('the pdf bytes', $signed['content_hash'], 'not-the-real-signature'));
    }

    public function testDifferentKeysProduceDifferentSignaturesForTheSameBytes(): void
    {
        $a = (new DocumentSigner('key-a'))->sign('same bytes');
        $b = (new DocumentSigner('key-b'))->sign('same bytes');

        self::assertSame($a['content_hash'], $b['content_hash']); // hash is key-independent
        self::assertNotSame($a['hmac_signature'], $b['hmac_signature']); // signature is not
    }
}
