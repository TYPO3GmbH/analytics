<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Returns the tracking code of the site of a frontend request.
 * TrackingCodeMiddleware inserts this code into the page, and
 * TrackingCodeContentSecurityPolicyListener allows it in the frontend CSP.
 */
final readonly class TrackingCodeResolver
{
    public function resolve(ServerRequestInterface $request): ?string
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return null;
        }

        $settings = $site->getSettings();
        $trackingCode = (string)$settings->get('trackingCode', '');
        if ($trackingCode === '' || $settings->get('status', '') !== 'active') {
            return null;
        }
        return $trackingCode;
    }
}
