<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface CipherServiceInterface
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $encrypted): string;

    public function isLegacyFormat(string $encrypted): bool;
}
