<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use T3G\Analytics\Service\BackendPageAccessCheckerInterface;
use T3G\Analytics\Service\DashboardLinkBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DashboardLinkBuilderTest extends UnitTestCase
{
    private SiteFinder&MockObject $siteFinder;
    private UriBuilder&MockObject $uriBuilder;
    /** @var list<int> */
    private array $accessibleRootPages = [];
    private DashboardLinkBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteFinder = $this->createMock(SiteFinder::class);
        $this->siteFinder->method('getSiteByIdentifier')->willReturnCallback(fn (string $identifier): Site => match ($identifier) {
            'main' => $this->buildSite('main', 1, []),
            'second' => $this->buildSite('second', 2, ['attachedTo' => 'main']),
            'orphan' => $this->buildSite('orphan', 3, ['attachedTo' => 'removed']),
            default => throw new SiteNotFoundException('not found', 1),
        });

        $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);
        $pageAccessChecker->method('userCanAccessPage')->willReturnCallback(fn (int $pageId): bool => in_array($pageId, $this->accessibleRootPages, true));

        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            static fn (string $route, array $parameters): Uri => new Uri('/module/' . $route . '?' . http_build_query($parameters))
        );

        $this->subject = new DashboardLinkBuilder($this->siteFinder, $pageAccessChecker, $this->uriBuilder);
    }

    /** build */

    #[Test]
    public function buildReturnsTheDashboardLinkOfTheSite(): void
    {
        $this->accessibleRootPages = [1];

        self::assertSame(
            '/module/site_analytics.dashboard?siteIdentifier=main&days=7',
            $this->subject->build('main', ['days' => 7])
        );
    }

    #[Test]
    public function buildReturnsNothingForASiteAttachedToAnActiveSite(): void
    {
        $this->accessibleRootPages = [1, 2];

        self::assertSame('', $this->subject->build('second', ['days' => 7]));
    }

    #[Test]
    public function buildReturnsNothingWithoutAccessToTheSite(): void
    {
        self::assertSame('', $this->subject->build('main'));
    }

    #[Test]
    public function buildReturnsNothingWithoutSiteIdentifier(): void
    {
        $this->siteFinder->expects(self::never())->method('getSiteByIdentifier');

        self::assertSame('', $this->subject->build(''));
    }

    /** buildForPage */

    #[Test]
    public function buildForPageReturnsTheDashboardLinkOfTheSiteWithThePageFilter(): void
    {
        $this->accessibleRootPages = [1];

        self::assertSame(
            '/module/site_analytics.dashboard?siteIdentifier=main&days=7&pageUrl=https%3A%2F%2Fexample.com%2Fabout',
            $this->subject->buildForPage('main', 'https://example.com/about', ['days' => 7])
        );
    }

    #[Test]
    public function buildForPageReturnsTheDashboardLinkOfTheActiveSiteForAnAddedSite(): void
    {
        $this->accessibleRootPages = [1, 2];

        self::assertSame(
            '/module/site_analytics.dashboard?siteIdentifier=main&days=7&pageUrl=https%3A%2F%2Fsecond.example.com%2Fabout',
            $this->subject->buildForPage('second', 'https://second.example.com/about', ['days' => 7])
        );
    }

    #[Test]
    public function buildForPageReturnsNothingForAnAddedSiteWithoutAccessToTheActiveSite(): void
    {
        $this->accessibleRootPages = [2];

        self::assertSame('', $this->subject->buildForPage('second', 'https://second.example.com/about'));
    }

    #[Test]
    public function buildForPageReturnsNothingWhenTheActiveSiteDoesNotExist(): void
    {
        $this->accessibleRootPages = [3];

        self::assertSame('', $this->subject->buildForPage('orphan', 'https://orphan.example.com/'));
    }

    private function buildSite(string $identifier, int $rootPageId, array $settings): Site
    {
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        $site->method('getRootPageId')->willReturn($rootPageId);
        $site->method('getSettings')->willReturn(new SiteSettings(new Settings($settings), [], []));
        return $site;
    }
}
