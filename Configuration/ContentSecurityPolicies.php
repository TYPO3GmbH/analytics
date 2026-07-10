<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Type\Map;

$frameOrigins = [new UriValue('https://dashboard.analytics.typo3.com')];

$additionalFrameSrc = (string)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['additionalFrameSrc'] ?? '');
foreach (array_filter(array_map('trim', explode(',', $additionalFrameSrc))) as $origin) {
    // Only accept concrete http(s) origins; reject wildcards, scheme-only and CSP keyword values.
    if (preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $origin)) {
        $frameOrigins[] = new UriValue($origin);
    }
}

return Map::fromEntries([
    Scope::backend(),
    new MutationCollection(
        new Mutation(MutationMode::Extend, Directive::FrameSrc, ...$frameOrigins)
    ),
]);
