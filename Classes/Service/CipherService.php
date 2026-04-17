<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Symmetric encryption service using XChaCha20-Poly1305 via libsodium.
 *
 * On TYPO3 v14+ the built-in TYPO3\CMS\Core\Crypto\Cipher\CipherService is used
 * automatically (Feature-108002). On v13 an equivalent implementation based on
 * the same algorithm and serialisation format is used as fallback, so encrypted
 * values written under v13 remain decryptable after upgrading to v14.
 *
 * Key derivation mirrors KeyFactory::deriveSharedKeyFromEncryptionKey():
 * BLAKE2b keyed hash over a domain-specific seed derived from
 * $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'].
 */
class CipherService
{
    /** @internal class name of the v14 core service – kept as string to avoid autoload errors on v13 */
    private const string CORE_CIPHER_SERVICE = 'TYPO3\\CMS\\Core\\Crypto\\Cipher\\CipherService';
    private const string CORE_KEY_FACTORY = 'TYPO3\\CMS\\Core\\Crypto\\Cipher\\KeyFactory';

    /**
     * Domain seed used for key derivation – must match between encrypt/decrypt
     * and correspond to the seed passed to KeyFactory::deriveSharedKeyFromEncryptionKey()
     * on v14.
     */
    private const string KEY_SEED = 'tx_analytics.instanceSecret';

    public function encrypt(string $plaintext): string
    {
        if ($this->isCoreServiceAvailable()) {
            return $this->encryptWithCoreService($plaintext);
        }

        return $this->encryptWithSodium($plaintext);
    }

    /**
     * @throws \RuntimeException when decryption or authentication fails
     */
    public function decrypt(string $encrypted): string
    {
        if ($this->isCoreServiceAvailable()) {
            return $this->decryptWithCoreService($encrypted);
        }

        return $this->decryptWithSodium($encrypted);
    }

    // -------------------------------------------------------------------------
    // TYPO3 v14+ path
    // -------------------------------------------------------------------------

    private function encryptWithCoreService(string $plaintext): string
    {
        // @phpstan-ignore argument.type (class-string not resolvable in v13 – v14 forward-compat code)
        $keyFactory = GeneralUtility::makeInstance(self::CORE_KEY_FACTORY);
        // @phpstan-ignore argument.type (class-string not resolvable in v13 – v14 forward-compat code)
        $coreService = GeneralUtility::makeInstance(self::CORE_CIPHER_SERVICE);
        // @phpstan-ignore class.notFound (TYPO3\CMS\Core\Crypto\Cipher\KeyFactory does not exist in v13)
        $key = $keyFactory->deriveSharedKeyFromEncryptionKey(self::KEY_SEED);

        // @phpstan-ignore class.notFound (TYPO3\CMS\Core\Crypto\Cipher\CipherService does not exist in v13)
        return (string)$coreService->encrypt($plaintext, $key);
    }

    private function decryptWithCoreService(string $encrypted): string
    {
        // @phpstan-ignore argument.type (class-string not resolvable in v13 – v14 forward-compat code)
        $keyFactory = GeneralUtility::makeInstance(self::CORE_KEY_FACTORY);
        // @phpstan-ignore argument.type (class-string not resolvable in v13 – v14 forward-compat code)
        $coreService = GeneralUtility::makeInstance(self::CORE_CIPHER_SERVICE);
        // @phpstan-ignore class.notFound (TYPO3\CMS\Core\Crypto\Cipher\KeyFactory does not exist in v13)
        $key = $keyFactory->deriveSharedKeyFromEncryptionKey(self::KEY_SEED);

        // CipherValue::fromSerialized() reconstructs the value object from the serialised form
        $cipherValueClass = 'TYPO3\\CMS\\Core\\Crypto\\Cipher\\CipherValue';
        $cipherValue = $cipherValueClass::fromSerialized($encrypted);

        // @phpstan-ignore class.notFound (TYPO3\CMS\Core\Crypto\Cipher\CipherService does not exist in v13)
        return $coreService->decrypt($cipherValue, $key);
    }

    // -------------------------------------------------------------------------
    // TYPO3 v13 fallback path
    // -------------------------------------------------------------------------

    private function encryptWithSodium(string $plaintext): string
    {
        $key = $this->deriveKey();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            '',
            $nonce,
            $key
        );

        sodium_memzero($key);

        return sodium_bin2base64(
            (string)json_encode([
                'nonce' => sodium_bin2base64($nonce, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
                'ciphertext' => sodium_bin2base64($ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            ]),
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
        );
    }

    private function decryptWithSodium(string $encrypted): string
    {
        $key = $this->deriveKey();

        $payload = json_decode(
            sodium_base642bin($encrypted, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $nonce = sodium_base642bin((string) $payload['nonce'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $ciphertext = sodium_base642bin((string) $payload['ciphertext'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            '',
            $nonce,
            $key
        );

        sodium_memzero($key);

        if ($plaintext === false) {
            throw new \RuntimeException('Cipher decryption failed: authentication tag mismatch.', 1744800000);
        }

        return $plaintext;
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function isCoreServiceAvailable(): bool
    {
        return class_exists(self::CORE_CIPHER_SERVICE);
    }

    /**
     * Derives a 32-byte key from TYPO3's encryptionKey using BLAKE2b,
     * mirroring TYPO3 v14 KeyFactory::deriveSharedKeyFromEncryptionKey().
     */
    private function deriveKey(): string
    {
        $encryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';

        $masterKey = sodium_crypto_generichash((string) $encryptionKey, '', SODIUM_CRYPTO_GENERICHASH_KEYBYTES);

        return sodium_crypto_generichash(
            self::KEY_SEED,
            $masterKey,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
        );
    }
}
