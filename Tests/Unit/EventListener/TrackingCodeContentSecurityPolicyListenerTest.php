<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\EventListener\TrackingCodeContentSecurityPolicyListener;
use T3G\Analytics\Service\TrackingCodeResolver;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\HashValue;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Policy;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TrackingCodeContentSecurityPolicyListenerTest extends UnitTestCase
{
    private const LOADER = "(function(){var p=document.createElement('script');"
        . 'p.src="https://dashboard.analytics.typo3.com/analytics/main.js";document.body.appendChild(p)})()';

    private const TRACKING_CODE = '<!-- Tracking Code for https://example.com --><script>' . self::LOADER
        . '</script><!-- Tracking Code for https://example.com -->';

    private const DASHBOARD_ORIGIN = 'https://dashboard.analytics.typo3.com';

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['additionalFrameSrc']);
        parent::tearDown();
    }

    #[Test]
    public function addsHashOfInlineLoaderToScriptSrc(): void
    {
        $event = $this->buildEvent(Scope::frontend(), $this->buildSite(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']));

        $this->buildSubject()($event);

        $expectedHash = 'sha256-' . base64_encode(hash('sha256', self::LOADER, true));
        self::assertContains($expectedHash, $this->sources($event->getCurrentPolicy(), Directive::ScriptSrc));
        self::assertTrue($event->getCurrentPolicy()->containsDirective(Directive::ScriptSrc, SourceKeyword::self));
    }

    public static function directiveDataProvider(): \Generator
    {
        yield 'script-src' => [Directive::ScriptSrc];
        yield 'style-src' => [Directive::StyleSrc];
        yield 'img-src' => [Directive::ImgSrc];
        yield 'connect-src' => [Directive::ConnectSrc];
    }

    #[Test]
    #[DataProvider('directiveDataProvider')]
    public function addsDashboardOriginsToDirective(Directive $directive): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['additionalFrameSrc'] = 'https://dashboard.example.com';
        $event = $this->buildEvent(Scope::frontend(), $this->buildSite(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']));

        $this->buildSubject()($event);

        $sources = $this->sources($event->getCurrentPolicy(), $directive);
        self::assertContains(self::DASHBOARD_ORIGIN, $sources);
        self::assertContains('https://dashboard.example.com', $sources);
    }

    #[Test]
    public function doesNotAddHttpOriginsToFrontendPolicy(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['additionalFrameSrc'] = 'http://dashboard.example.com';
        $event = $this->buildEvent(Scope::frontend(), $this->buildSite(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']));

        $this->buildSubject()($event);

        $sources = $this->sources($event->getCurrentPolicy(), Directive::ScriptSrc);
        self::assertContains(self::DASHBOARD_ORIGIN, $sources);
        self::assertNotContains('http://dashboard.example.com', $sources);
    }

    #[Test]
    public function doesNotAddHashForExternalScript(): void
    {
        $trackingCode = '<script src="https://dashboard.analytics.typo3.com/analytics/main.js">' . self::LOADER . '</script>';
        $event = $this->buildEvent(Scope::frontend(), $this->buildSite(['trackingCode' => $trackingCode, 'status' => 'active']));

        $this->buildSubject()($event);

        self::assertSame([], array_filter(
            $this->sources($event->getCurrentPolicy(), Directive::ScriptSrc),
            static fn (string $source): bool => str_starts_with($source, 'sha256-')
        ));
    }

    #[Test]
    public function addsHashForInlineScriptWithDataSrcAttribute(): void
    {
        $trackingCode = '<script data-src="https://example.com/x.js">' . self::LOADER . '</script>';
        $event = $this->buildEvent(Scope::frontend(), $this->buildSite(['trackingCode' => $trackingCode, 'status' => 'active']));

        $this->buildSubject()($event);

        $expectedHash = 'sha256-' . base64_encode(hash('sha256', self::LOADER, true));
        self::assertContains($expectedHash, $this->sources($event->getCurrentPolicy(), Directive::ScriptSrc));
    }

    #[Test]
    public function hashesInlineScriptWithLineBreaksConvertedToLineFeed(): void
    {
        $trackingCode = "<script>var a = 1;\r\nvar b = 2;\rvar c = 3;</script>";
        $event = $this->buildEvent(Scope::frontend(), $this->buildSite(['trackingCode' => $trackingCode, 'status' => 'active']));

        $this->buildSubject()($event);

        $expectedHash = 'sha256-' . base64_encode(hash('sha256', "var a = 1;\nvar b = 2;\nvar c = 3;", true));
        self::assertContains($expectedHash, $this->sources($event->getCurrentPolicy(), Directive::ScriptSrc));
    }

    #[Test]
    public function doesNotCreateDirectivesThatDoNotRestrictThePage(): void
    {
        $policy = (new Policy())->set(Directive::ScriptSrc, SourceKeyword::self);
        $event = $this->buildEvent(Scope::frontend(), $this->buildSite(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']), $policy);

        $this->buildSubject()($event);

        $result = $event->getCurrentPolicy();
        self::assertContains(self::DASHBOARD_ORIGIN, $this->sources($result, Directive::ScriptSrc));
        self::assertFalse($result->has(Directive::StyleSrc));
        self::assertFalse($result->has(Directive::ImgSrc));
        self::assertFalse($result->has(Directive::ConnectSrc));
        self::assertFalse($result->has(Directive::ScriptSrcElem));
        self::assertFalse($result->has(Directive::StyleSrcElem));
    }

    public static function unsafeInlinePolicyDataProvider(): \Generator
    {
        yield 'script-src' => [(new Policy())->set(Directive::ScriptSrc, SourceKeyword::self, SourceKeyword::unsafeInline)];
        yield 'inherited from default-src' => [(new Policy())->set(Directive::DefaultSrc, SourceKeyword::self, SourceKeyword::unsafeInline)];
    }

    #[Test]
    #[DataProvider('unsafeInlinePolicyDataProvider')]
    public function doesNotAddHashesWhenScriptSrcAllowsUnsafeInline(Policy $policy): void
    {
        $event = $this->buildEvent(Scope::frontend(), $this->buildSite(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']), $policy);

        $this->buildSubject()($event);

        $result = $event->getCurrentPolicy();
        $sources = $this->sources($result, Directive::ScriptSrc);
        self::assertContains(self::DASHBOARD_ORIGIN, $sources);
        self::assertSame([], array_filter($sources, static fn (string $source): bool => str_starts_with($source, 'sha256-')));
        self::assertTrue($result->containsDirective(Directive::ScriptSrc, SourceKeyword::unsafeInline));
    }

    #[Test]
    public function extendsScriptSrcElemAndStyleSrcElemWhenSet(): void
    {
        $policy = (new Policy())
            ->set(Directive::DefaultSrc, SourceKeyword::self)
            ->set(Directive::ScriptSrcElem, SourceKeyword::self)
            ->set(Directive::StyleSrcElem, SourceKeyword::self);
        $event = $this->buildEvent(Scope::frontend(), $this->buildSite(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']), $policy);

        $this->buildSubject()($event);

        $result = $event->getCurrentPolicy();
        $expectedHash = 'sha256-' . base64_encode(hash('sha256', self::LOADER, true));
        self::assertContains(self::DASHBOARD_ORIGIN, $this->sources($result, Directive::ScriptSrcElem));
        self::assertContains($expectedHash, $this->sources($result, Directive::ScriptSrcElem));
        self::assertContains(self::DASHBOARD_ORIGIN, $this->sources($result, Directive::StyleSrcElem));
    }

    public static function inactiveTrackingDataProvider(): \Generator
    {
        yield 'no tracking code' => [['status' => 'active']];
        yield 'status pending' => [['trackingCode' => self::TRACKING_CODE, 'status' => 'pending']];
        yield 'no status' => [['trackingCode' => self::TRACKING_CODE]];
    }

    /**
     * @param array<string, string> $settings
     */
    #[Test]
    #[DataProvider('inactiveTrackingDataProvider')]
    public function doesNothingWithoutActiveTracking(array $settings): void
    {
        $event = $this->buildEvent(Scope::frontend(), $this->buildSite($settings));
        $policy = $event->getCurrentPolicy();

        $this->buildSubject()($event);

        self::assertSame($policy, $event->getCurrentPolicy());
    }

    #[Test]
    public function doesNothingWithoutRequest(): void
    {
        $policy = (new Policy())->set(Directive::DefaultSrc, SourceKeyword::self)->set(Directive::ScriptSrc, SourceKeyword::self);
        $event = new PolicyMutatedEvent(Scope::frontendSite($this->buildSite(['trackingCode' => self::TRACKING_CODE, 'status' => 'active'])), null, $policy, $policy);

        $this->buildSubject()($event);

        self::assertSame($policy, $event->getCurrentPolicy());
    }

    #[Test]
    public function doesNothingInBackendScope(): void
    {
        $event = $this->buildEvent(Scope::backend(), $this->buildSite(['trackingCode' => self::TRACKING_CODE, 'status' => 'active']));
        $policy = $event->getCurrentPolicy();

        $this->buildSubject()($event);

        self::assertSame($policy, $event->getCurrentPolicy());
    }

    private function buildSubject(): TrackingCodeContentSecurityPolicyListener
    {
        return new TrackingCodeContentSecurityPolicyListener(new ApiConfiguration(), new TrackingCodeResolver());
    }

    /**
     * @return list<string>
     */
    private function sources(Policy $policy, Directive $directive): array
    {
        $sources = [];
        foreach ($policy->get($directive)?->sources ?? [] as $source) {
            if ($source instanceof HashValue) {
                $sources[] = trim((string)$source, "'");
            } elseif ($source instanceof UriValue) {
                $sources[] = (string)$source;
            }
        }
        return $sources;
    }

    private function buildEvent(Scope $scope, Site $site, ?Policy $policy = null): PolicyMutatedEvent
    {
        $request = (new ServerRequest('https://example.com/', 'GET'))->withAttribute('site', $site);
        $policy ??= (new Policy())->set(Directive::DefaultSrc, SourceKeyword::self)->set(Directive::ScriptSrc, SourceKeyword::self);
        return new PolicyMutatedEvent($scope, $request, $policy, $policy);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function buildSite(array $settings): Site
    {
        return new Site('main', 1, ['base' => 'https://example.com/', 'settings' => $settings]);
    }
}
