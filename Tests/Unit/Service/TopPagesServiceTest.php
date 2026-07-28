<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use T3G\Analytics\Service\AnalyticsDataClientInterface;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\BackendPageAccessCheckerInterface;
use T3G\Analytics\Service\MetricFormatter;
use T3G\Analytics\Service\TopPagesService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\RouteNotFoundException;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TopPagesServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private TopPagesService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new TopPagesService(
            $this->createMock(AnalyticsDataClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(FrontendInterface::class),
            $this->createMock(BackendPageAccessCheckerInterface::class),
            new MetricFormatter(),
            $this->createMock(AnalyticsSiteProviderInterface::class),
            $this->createMock(UriBuilder::class),
        );
    }

    /** annotateWithPageAccess (via loadTopPagesData) */

    #[Test]
    public function loadTopPagesDataSetsPageIdWhenUserCanAccessPageAndLanguage(): void
    {
        $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);
        $pageAccessChecker->method('userCanAccessPage')->with(42)->willReturn(true);
        $pageAccessChecker->method('userCanAccessLanguage')->with(0)->willReturn(true);

        $site = $this->makeSiteMock('https://example.com/', new PageArguments(42, '0', []));

        $subject = $this->makeSubjectWithMockedFetch(
            $pageAccessChecker,
            $site,
            [['pageUrl' => 'https://example.com/about', 'visitCount' => 10]],
        );

        $result = $subject->loadTopPagesData('site1', 7);

        self::assertCount(1, $result);
        self::assertSame(42, $result[0]['pageId']);
    }

    #[Test]
    public function loadTopPagesDataIncludesPageWithoutPageIdWhenUserCannotAccessLanguage(): void
    {
        $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);
        $pageAccessChecker->method('userCanAccessPage')->with(42)->willReturn(true);
        $pageAccessChecker->method('userCanAccessLanguage')->with(0)->willReturn(false);

        $site = $this->makeSiteMock('https://example.com/', new PageArguments(42, '0', []));

        $subject = $this->makeSubjectWithMockedFetch(
            $pageAccessChecker,
            $site,
            [['pageUrl' => 'https://example.com/about', 'visitCount' => 10]],
        );

        $result = $subject->loadTopPagesData('site1', 7);

        self::assertCount(1, $result);
        self::assertArrayNotHasKey('pageId', $result[0]);
    }

    #[Test]
    public function loadTopPagesDataIncludesPageWithoutPageIdWhenUserCannotAccessPage(): void
    {
        // Page is shown (analytics data stays visible) but no page-module link.
        $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);
        $pageAccessChecker->method('userCanAccessPage')->with(42)->willReturn(false);

        $site = $this->makeSiteMock('https://example.com/', new PageArguments(42, '0', []));

        $subject = $this->makeSubjectWithMockedFetch(
            $pageAccessChecker,
            $site,
            [['pageUrl' => 'https://example.com/about', 'visitCount' => 10]],
        );

        $result = $subject->loadTopPagesData('site1', 7);

        self::assertCount(1, $result);
        self::assertArrayNotHasKey('pageId', $result[0]);
    }

    #[Test]
    public function loadTopPagesDataIncludesPageWithoutPageIdWhenUserHasLanguageAccessButNotPageAccess(): void
    {
        // Language access alone is not sufficient for the page-module link.
        $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);
        $pageAccessChecker->method('userCanAccessLanguage')->with(0)->willReturn(true);
        $pageAccessChecker->method('userCanAccessPage')->with(42)->willReturn(false);

        $site = $this->makeSiteMock('https://example.com/', new PageArguments(42, '0', []));

        $subject = $this->makeSubjectWithMockedFetch(
            $pageAccessChecker,
            $site,
            [['pageUrl' => 'https://example.com/restricted', 'visitCount' => 5]],
        );

        $result = $subject->loadTopPagesData('site1', 7);

        self::assertCount(1, $result);
        self::assertSame('https://example.com/restricted', $result[0]['pageUrl']);
        self::assertArrayNotHasKey('pageId', $result[0]);
    }

    #[Test]
    public function loadTopPagesDataExcludesPageWhenUrlDoesNotMatchSiteDomain(): void
    {
        $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);
        $pageAccessChecker->expects(self::never())->method('userCanAccessPage');

        $site = $this->makeSiteMock('https://other.com/', new PageArguments(1, '0', []));

        $subject = $this->makeSubjectWithMockedFetch(
            $pageAccessChecker,
            $site,
            [['pageUrl' => 'https://example.com/about', 'visitCount' => 10]],
        );

        $result = $subject->loadTopPagesData('site1', 7);

        self::assertSame([], $result);
    }

    #[Test]
    public function loadTopPagesDataIncludesPageWithoutPageIdWhenRouterCannotMatchUrl(): void
    {
        // URL is on the correct domain but can't be routed (e.g. deleted page) → show without page-module link.
        $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);
        $pageAccessChecker->expects(self::never())->method('userCanAccessPage');

        $site = $this->makeSiteMock('https://example.com/', routerThrows: true);

        $subject = $this->makeSubjectWithMockedFetch(
            $pageAccessChecker,
            $site,
            [['pageUrl' => 'https://example.com/deleted-page', 'visitCount' => 10]],
        );

        $result = $subject->loadTopPagesData('site1', 7);

        self::assertCount(1, $result);
        self::assertArrayNotHasKey('pageId', $result[0]);
    }

    #[Test]
    public function loadTopPagesDataExcludesPageWithEmptyUrl(): void
    {
        $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);
        $pageAccessChecker->expects(self::never())->method('userCanAccessPage');

        $site = $this->makeSiteMock('https://example.com/', new PageArguments(1, '0', []));

        $subject = $this->makeSubjectWithMockedFetch(
            $pageAccessChecker,
            $site,
            [['pageUrl' => '', 'visitCount' => 10]],
        );

        $result = $subject->loadTopPagesData('site1', 7);

        self::assertSame([], $result);
    }

    /** buildPageItems */

    #[Test]
    public function buildPageItemsAssignsPositionsStartingAtOne(): void
    {
        $pages = [
            $this->row('https://example.com/', 100),
            $this->row('https://example.com/about', 50),
        ];

        $result = $this->subject->buildPageItems($pages, 'vs. prev.');

        self::assertSame(1, $result[0]['position']);
        self::assertSame(2, $result[1]['position']);
    }

    #[Test]
    public function buildPageItemsUsesPageTitleWhenPresent(): void
    {
        $pages = [$this->row('https://example.com/', 10, pageTitle: 'Home')];

        $result = $this->subject->buildPageItems($pages, '');

        self::assertSame('Home', $result[0]['title']);
    }

    #[Test]
    public function buildPageItemsFallsBackToUrlWhenNoPageTitle(): void
    {
        $pages = [$this->row('https://example.com/no-title', 10)];

        $result = $this->subject->buildPageItems($pages, '');

        self::assertSame('https://example.com/no-title', $result[0]['title']);
    }

    #[Test]
    public function buildPageItemsFormatsLargeVisitCountWithThousandSeparator(): void
    {
        $pages = [$this->row('https://example.com/', 1234)];

        $result = $this->subject->buildPageItems($pages, '');

        self::assertSame('1.234', $result[0]['visitCount']);
    }

    #[Test]
    public function buildPageItemsFormatsVisitPercentOfTotalAsWidth(): void
    {
        $pages = [$this->row('https://example.com/', 10, visitPercentOfTotal: 33.33)];

        $result = $this->subject->buildPageItems($pages, '');

        self::assertSame('33.33', $result[0]['visitPercentOfTotal']);
    }

    #[Test]
    public function buildPageItemsClipsVisitPercentOfTotalAt100(): void
    {
        $pages = [$this->row('https://example.com/', 10, visitPercentOfTotal: 150.0)];

        $result = $this->subject->buildPageItems($pages, '');

        self::assertSame('100.00', $result[0]['visitPercentOfTotal']);
    }

    #[Test]
    public function buildPageItemsHasNoTrendWhenPreviousVisitCountIsZero(): void
    {
        $pages = [$this->row('https://example.com/', visitCount: 20, previousVisitCount: 0)];

        $result = $this->subject->buildPageItems($pages, 'label');

        self::assertSame('', $result[0]['trend']);
        self::assertSame('neutral', $result[0]['trendDirection']);
    }

    #[Test]
    public function buildPageItemsShowsUpTrendWhenVisitsIncreased(): void
    {
        $pages = [$this->row('https://example.com/', visitCount: 20, previousVisitCount: 10, visitCountPercentageChange: 100.0)];

        $result = $this->subject->buildPageItems($pages, 'label');

        self::assertSame('+100.00%', $result[0]['trend']);
        self::assertSame('up', $result[0]['trendDirection']);
    }

    #[Test]
    public function buildPageItemsShowsDownTrendWhenVisitsDecreased(): void
    {
        $pages = [$this->row('https://example.com/', visitCount: 5, previousVisitCount: 10, visitCountPercentageChange: -50.0)];

        $result = $this->subject->buildPageItems($pages, 'label');

        self::assertSame('-50.00%', $result[0]['trend']);
        self::assertSame('down', $result[0]['trendDirection']);
    }

    #[Test]
    public function buildPageItemsHasNoTrendWhenChangeIsZero(): void
    {
        $pages = [$this->row('https://example.com/', visitCount: 10, previousVisitCount: 10, visitCountPercentageChange: 0.0)];

        $result = $this->subject->buildPageItems($pages, 'label');

        self::assertSame('', $result[0]['trend']);
        self::assertSame('neutral', $result[0]['trendDirection']);
    }

    #[Test]
    public function buildPageItemsIncludesTrendLabelInEveryRow(): void
    {
        $pages = [
            $this->row('https://example.com/', 100),
            $this->row('https://example.com/about', 50),
        ];

        $result = $this->subject->buildPageItems($pages, 'Compared to previous period');

        self::assertSame('Compared to previous period', $result[0]['trendLabel']);
        self::assertSame('Compared to previous period', $result[1]['trendLabel']);
    }

    #[Test]
    public function buildPageItemsFormatsDecimalPercentageChange(): void
    {
        $pages = [$this->row('https://example.com/', visitCount: 15, previousVisitCount: 10, visitCountPercentageChange: 50.0)];

        $result = $this->subject->buildPageItems($pages, '');

        self::assertSame('+50.00%', $result[0]['trend']);
    }

    #[Test]
    public function buildPageItemsReturnsEmptyArrayForEmptyInput(): void
    {
        $result = $this->subject->buildPageItems([], 'label');

        self::assertSame([], $result);
    }

    /** buildPageItems — slug */

    #[Test]
    public function buildPageItemsSetsSlugFromUrlPathWhenPageIdIsKnown(): void
    {
        $pages = [array_merge($this->row('https://example.com/about/us', 10), ['pageId' => 5, 'languageId' => 0])];

        $result = $this->subject->buildPageItems($pages, '');

        self::assertSame('/about/us', $result[0]['slug']);
    }

    #[Test]
    public function buildPageItemsSetsSlugToEmptyStringWhenPageIdIsNull(): void
    {
        $pages = [$this->row('https://example.com/about', 10)];

        $result = $this->subject->buildPageItems($pages, '');

        self::assertSame('', $result[0]['slug']);
    }

    #[Test]
    public function buildPageItemsSetsSlugToSlashForRootPageWithTrailingSlash(): void
    {
        $pages = [array_merge($this->row('https://example.com/', 10), ['pageId' => 1, 'languageId' => 0])];

        $result = $this->subject->buildPageItems($pages, '');

        self::assertSame('/', $result[0]['slug']);
    }

    #[Test]
    public function buildPageItemsSetsSlugToSlashForRootPageWithoutPath(): void
    {
        $pages = [array_merge($this->row('https://example.com', 10), ['pageId' => 1, 'languageId' => 0])];

        $result = $this->subject->buildPageItems($pages, '');

        self::assertSame('/', $result[0]['slug']);
    }

    /** buildPageItems — pageModuleUri */

    #[Test]
    public function buildPageItemsSetsPageModuleUriToNullWhenPageIdIsNull(): void
    {
        $pages = [$this->row('https://example.com/about', 10)];

        $result = $this->subject->buildPageItems($pages, '');

        self::assertNull($result[0]['pageModuleUri']);
    }

    #[Test]
    public function buildPageItemsSetsPageModuleUriWhenPageIdIsKnown(): void
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->expects(self::once())
            ->method('buildUriFromRoute')
            ->with('web_layout', ['id' => 42, 'languages' => [0]])
            ->willReturn(new Uri('/typo3/module/web/layout?id=42&languages%5B0%5D=0'));

        $pages = [array_merge($this->row('https://example.com/about', 10), ['pageId' => 42, 'languageId' => 0])];

        $result = (new \T3G\Analytics\Service\TopPagesService(
            $this->createMock(AnalyticsDataClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(FrontendInterface::class),
            $this->createMock(BackendPageAccessCheckerInterface::class),
            new MetricFormatter(),
            $this->createMock(AnalyticsSiteProviderInterface::class),
            $uriBuilder,
        ))->buildPageItems($pages, '');

        self::assertSame('/typo3/module/web/layout?id=42&languages%5B0%5D=0', $result[0]['pageModuleUri']);
    }

    #[Test]
    public function buildPageItemsIncludesLanguagesParamInPageModuleUri(): void
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->expects(self::once())
            ->method('buildUriFromRoute')
            ->with('web_layout', ['id' => 5, 'languages' => [1]])
            ->willReturn(new Uri('/typo3/module/web/layout?id=5&languages%5B0%5D=1'));

        $pages = [array_merge($this->row('https://example.com/de/seite', 10), ['pageId' => 5, 'languageId' => 1])];

        $result = (new \T3G\Analytics\Service\TopPagesService(
            $this->createMock(AnalyticsDataClientInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(FrontendInterface::class),
            $this->createMock(BackendPageAccessCheckerInterface::class),
            new MetricFormatter(),
            $this->createMock(AnalyticsSiteProviderInterface::class),
            $uriBuilder,
        ))->buildPageItems($pages, '');

        self::assertSame('/typo3/module/web/layout?id=5&languages%5B0%5D=1', $result[0]['pageModuleUri']);
    }

    #[Test]
    public function loadTopPagesDataSetsFlagIdentifierFromMatchedLanguage(): void
    {
        $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);
        $pageAccessChecker->method('userCanAccessPage')->willReturn(true);
        $pageAccessChecker->method('userCanAccessLanguage')->willReturn(true);

        $site = $this->makeSiteMock('https://example.com/', new PageArguments(1, '0', []), flagIdentifier: 'flags-de');

        $subject = $this->makeSubjectWithMockedFetch(
            $pageAccessChecker,
            $site,
            [['pageUrl' => 'https://example.com/about', 'visitCount' => 10]],
        );

        $result = $subject->loadTopPagesData('site1', 7);

        self::assertCount(1, $result);
        self::assertSame('flags-de', $result[0]['flagIdentifier']);
    }

    #[Test]
    public function loadTopPagesDataSetsFlagIdentifierEvenWhenRouterCannotMatchUrl(): void
    {
        // Language is determinable from the base URL even if the page itself can't be routed.
        $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);

        $site = $this->makeSiteMock('https://example.com/', routerThrows: true, flagIdentifier: 'flags-de');

        $subject = $this->makeSubjectWithMockedFetch(
            $pageAccessChecker,
            $site,
            [['pageUrl' => 'https://example.com/deleted-page', 'visitCount' => 10]],
        );

        $result = $subject->loadTopPagesData('site1', 7);

        self::assertCount(1, $result);
        self::assertSame('flags-de', $result[0]['flagIdentifier']);
    }

    #[Test]
    public function loadTopPagesDataDoesNotSetFlagIdentifierWhenDomainDoesNotMatch(): void
    {
        $pageAccessChecker = $this->createMock(BackendPageAccessCheckerInterface::class);

        $site = $this->makeSiteMock('https://other.com/', new PageArguments(1, '0', []), flagIdentifier: 'flags-de');

        $subject = $this->makeSubjectWithMockedFetch(
            $pageAccessChecker,
            $site,
            [['pageUrl' => 'https://example.com/about', 'visitCount' => 10]],
        );

        $result = $subject->loadTopPagesData('site1', 7);

        // Page is filtered out entirely when the domain doesn't match.
        self::assertSame([], $result);
    }

    private function makeSiteMock(
        string $baseUrl,
        ?PageArguments $routerReturns = null,
        bool $routerThrows = false,
        string $flagIdentifier = '',
    ): Site {
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getBase')->willReturn(new Uri($baseUrl));
        $language->method('getFlagIdentifier')->willReturn($flagIdentifier);
        $language->method('getLanguageId')->willReturn(0);

        $router = $this->createMock(RouterInterface::class);
        if ($routerThrows) {
            $router->method('matchRequest')->willThrowException(new RouteNotFoundException('not found', 1));
        } else {
            $router->method('matchRequest')->willReturn($routerReturns ?? $this->createMock(PageArguments::class));
        }

        $site = $this->createMock(Site::class);
        $site->method('getLanguages')->willReturn([$language]);
        $site->method('getRouter')->willReturn($router);

        return $site;
    }

    /**
     * @param list<array<string, mixed>> $pages
     */
    private function makeSubjectWithMockedFetch(
        BackendPageAccessCheckerInterface $pageAccessChecker,
        Site $site,
        array $pages,
    ): TopPagesService {
        $analyticsClient = $this->createMock(AnalyticsDataClientInterface::class);
        $analyticsClient->method('fetchTopPages')->willReturn($pages);

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        $cache->method('set');

        $siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);
        $siteProvider->method('resolveAnalyticsSite')->willReturn([
            'site' => $site,
            'websiteId' => 'ws1',
            'apiKey' => 'key',
        ]);

        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn(new \TYPO3\CMS\Core\Http\Uri('/typo3/module/web/layout'));

        return new TopPagesService(
            $analyticsClient,
            $this->createMock(LoggerInterface::class),
            $cache,
            $pageAccessChecker,
            new MetricFormatter(),
            $siteProvider,
            $uriBuilder,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $pageUrl,
        int $visitCount = 10,
        int $previousVisitCount = 0,
        float $visitCountPercentageChange = 0.0,
        float $visitPercentOfTotal = 100.0,
        string $pageTitle = '',
    ): array {
        $row = [
            'pageUrl' => $pageUrl,
            'visitCount' => $visitCount,
            'previousVisitCount' => $previousVisitCount,
            'visitCountPercentageChange' => $visitCountPercentageChange,
            'visitPercentOfTotal' => $visitPercentOfTotal,
        ];
        if ($pageTitle !== '') {
            $row['pageTitle'] = $pageTitle;
        }
        return $row;
    }
}
