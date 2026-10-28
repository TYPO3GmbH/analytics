<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Renders a real frontend page with an enforced CSP. The browser runs the
 * inline loader only when the CSP header contains the hash of exactly the
 * script that the middleware inserts.
 */
final class TrackingCodeInFrontendTest extends FunctionalTestCase
{
    private const LOADER = "(function(){var p=document.createElement('script');"
        . 'p.src="https://dashboard.analytics.typo3.com/analytics/main.js";document.body.appendChild(p)})()';

    private const TRACKING_CODE = '<!-- Tracking Code for http://localhost --><script>' . self::LOADER
        . '</script><!-- Tracking Code for http://localhost -->';

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->setUpFrontendRootPage(1, ['EXT:analytics/Tests/Functional/Frontend/Fixtures/page.typoscript']);
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('main', [
            'rootPageId' => 1,
            'base' => 'http://localhost/',
            'dependencies' => ['t3g/analytics'],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ],
        ]);
        $siteWriter->writeSettings('main', ['trackingCode' => self::TRACKING_CODE, 'status' => 'active']);
    }

    #[Test]
    public function cspHeaderAllowsTheInsertedTrackingCode(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('http://localhost/'));
        $body = (string)$response->getBody();
        $csp = $response->getHeaderLine('Content-Security-Policy');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString("\n" . self::TRACKING_CODE . "\n</body>", $body);
        self::assertSame((string)strlen($body), $response->getHeaderLine('Content-Length'));
        self::assertMatchesRegularExpression('/(^|;)\s*script-src [^;]*\'sha256-' . preg_quote(base64_encode(hash('sha256', self::LOADER, true)), '/') . '\'/', $csp);
        self::assertMatchesRegularExpression('/(^|;)\s*script-src [^;]*https:\/\/dashboard\.analytics\.typo3\.com/', $csp);
    }
}
