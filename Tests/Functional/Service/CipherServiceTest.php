<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use T3G\Analytics\Service\CipherService;

/**
 * Tests the real sodium-based encryption path (TYPO3 v13 fallback).
 *
 * No TYPO3 instance or database is required – this is a plain PHPUnit test
 * that sets encryptionKey in $GLOBALS and exercises the actual sodium calls.
 */
final class CipherServiceTest extends TestCase
{
    private CipherService $subject;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']
            = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

        $this->subject = new CipherService();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
    }

    #[Test]
    public function encryptAndDecryptRoundtrip(): void
    {
        $plaintext = 'my-super-secret-instance-secret';
        $encrypted = $this->subject->encrypt($plaintext);

        self::assertNotSame($plaintext, $encrypted, 'Encrypted value must differ from plaintext');
        self::assertSame($plaintext, $this->subject->decrypt($encrypted));
    }

    #[Test]
    public function encryptProducesDifferentCiphertextEachTime(): void
    {
        $plaintext = 'same-plaintext';
        $first = $this->subject->encrypt($plaintext);
        $second = $this->subject->encrypt($plaintext);

        // Random nonce ensures ciphertexts differ even for identical plaintexts
        self::assertNotSame($first, $second);
        self::assertSame($plaintext, $this->subject->decrypt($first));
        self::assertSame($plaintext, $this->subject->decrypt($second));
    }

    #[Test]
    public function decryptThrowsOnTamperedCiphertext(): void
    {
        $encrypted = $this->subject->encrypt('secret');
        // Corrupt the last base64 character to invalidate the authentication tag
        $tampered = substr($encrypted, 0, -1) . ($encrypted[-1] === 'A' ? 'B' : 'A');

        $this->expectException(\Throwable::class);
        $this->subject->decrypt($tampered);
    }

    #[Test]
    public function emptyStringRoundtrip(): void
    {
        $encrypted = $this->subject->encrypt('');
        self::assertSame('', $this->subject->decrypt($encrypted));
    }
}
