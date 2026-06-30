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
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
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
        );
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
