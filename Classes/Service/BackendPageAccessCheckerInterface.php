<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface BackendPageAccessCheckerInterface
{
    public function userCanAccessPage(int $pageId): bool;

    public function userCanAccessLanguage(int $languageId): bool;
}
