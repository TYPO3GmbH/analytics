<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface CipherServiceInterface
{
    public function encrypt(#[\SensitiveParameter] string $plaintext): string;

    public function decrypt(#[\SensitiveParameter] string $encrypted): string;

    public function isLegacyFormat(#[\SensitiveParameter] string $encrypted): bool;
}
