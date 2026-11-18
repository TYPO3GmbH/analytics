<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Disposition;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyProvider;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Site\Entity\Site;

final class FrontendTrackingConfigurationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'core',
        'backend',
        'dashboard',
        'frontend',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'features' => [
                'security.frontend.enforceContentSecurityPolicy' => true,
            ],
        ],
    ];

    #[Test]
    public function trackingCodeMiddlewareRunsInsideContentLengthMiddleware(): void
    {
        // The resolved stack starts with the innermost middleware.
        $identifiers = array_keys((array)$this->get(MiddlewareStackResolver::class)->resolve('frontend'));

        $trackingCode = array_search('t3g/analytics/tracking-code', $identifiers, true);
        $contentLength = array_search('typo3/cms-frontend/content-length-headers', $identifiers, true);
        $site = array_search('typo3/cms-frontend/site', $identifiers, true);

        self::assertIsInt($trackingCode);
        self::assertIsInt($contentLength);
        self::assertIsInt($site);
        self::assertLessThan($contentLength, $trackingCode);
        self::assertLessThan($site, $trackingCode);
    }

    public static function frontendDirectiveDataProvider(): \Generator
    {
        yield 'script-src' => [Directive::ScriptSrc];
        yield 'style-src' => [Directive::StyleSrc];
        yield 'img-src' => [Directive::ImgSrc];
        yield 'connect-src' => [Directive::ConnectSrc];
    }

    #[Test]
    #[DataProvider('frontendDirectiveDataProvider')]
    public function frontendPolicyAllowsDashboardHostForSiteWithActiveTracking(Directive $directive): void
    {
        $policy = $this->get(PolicyProvider::class)->provideFor(Scope::frontend(), Disposition::enforce, $this->buildRequest('active'));

        self::assertTrue($policy->coversDirective($directive, new UriValue('https://dashboard.analytics.typo3.com/analytics/main.js')));
    }

    #[Test]
    #[DataProvider('frontendDirectiveDataProvider')]
    public function frontendPolicyDoesNotAllowDashboardHostForSiteWithoutActiveTracking(Directive $directive): void
    {
        $policy = $this->get(PolicyProvider::class)->provideFor(Scope::frontend(), Disposition::enforce, $this->buildRequest('pending'));

        self::assertFalse($policy->coversDirective($directive, new UriValue('https://dashboard.analytics.typo3.com/analytics/main.js')));
    }

    private function buildRequest(string $status): ServerRequest
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'settings' => [
                'trackingCode' => '<script>va()</script>',
                'status' => $status,
            ],
        ]);
        return (new ServerRequest('https://example.com/', 'GET'))->withAttribute('site', $site);
    }
}
