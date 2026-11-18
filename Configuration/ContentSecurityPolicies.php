<?php

declare(strict_types=1);

use T3G\Analytics\Configuration\ApiConfiguration;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Type\Map;

$frameOrigins = array_map(
    static fn (string $origin): UriValue => new UriValue($origin),
    (new ApiConfiguration())->getDashboardOrigins()
);

return Map::fromEntries([
    Scope::backend(),
    new MutationCollection(
        new Mutation(MutationMode::Extend, Directive::FrameSrc, ...$frameOrigins)
    ),
]);
