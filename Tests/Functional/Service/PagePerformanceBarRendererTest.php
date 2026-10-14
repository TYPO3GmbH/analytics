<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Service\PagePerformanceBarRenderer;
use T3G\Analytics\Service\TrendNote;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Renders the Fluid templates of the page performance bar.
 */
final class PagePerformanceBarRendererTest extends FunctionalTestCase
{
    private PagePerformanceBarRenderer $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $this->setDetailsExpanded(false);
        $this->subject = $this->get(PagePerformanceBarRenderer::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /** renderSkeleton */

    #[Test]
    public function renderSkeletonHoldsPageLanguageAndPeriodForTheJavaScript(): void
    {
        $bar = $this->querySelector($this->subject->renderSkeleton(5, 1, 30), '//section');

        self::assertSame('5', $bar->getAttribute('data-page-id'));
        self::assertSame('1', $bar->getAttribute('data-language-id'));
        self::assertSame('30', $bar->getAttribute('data-days'));
        self::assertSame('true', $bar->getAttribute('aria-busy'));
        self::assertStringContainsString('tx-analytics-performance-bar--loading', $bar->getAttribute('class'));
    }

    /** renderBar */

    #[Test]
    public function renderBarRendersCollapsedDetailsPanelByDefault(): void
    {
        $xpath = $this->xpath($this->renderBar());

        $toggle = $xpath->query('//button[contains(@class, "tx-analytics-performance-details-toggle")]')->item(0);
        $details = $xpath->query('//*[@id="tx-analytics-performance-details-5"]')->item(0);
        self::assertSame('false', $toggle->getAttribute('aria-expanded'));
        self::assertSame('tx-analytics-performance-details-5', $toggle->getAttribute('aria-controls'));
        self::assertTrue($details->hasAttribute('hidden'));
        self::assertSame(4, $xpath->query('//div[@class="tx-analytics-performance-detail"]')->length);
        self::assertStringNotContainsString('tx-analytics-performance-bar--expanded', $xpath->query('//section')->item(0)->getAttribute('class'));
    }

    #[Test]
    public function renderBarRendersExpandedDetailsPanelWhenStoredInUserSettings(): void
    {
        $this->setDetailsExpanded(true);

        $xpath = $this->xpath($this->renderBar());

        self::assertSame('true', $xpath->query('//button[contains(@class, "tx-analytics-performance-details-toggle")]')->item(0)->getAttribute('aria-expanded'));
        self::assertFalse($xpath->query('//*[@id="tx-analytics-performance-details-5"]')->item(0)->hasAttribute('hidden'));
        self::assertStringContainsString('tx-analytics-performance-bar--expanded', $xpath->query('//section')->item(0)->getAttribute('class'));
    }

    #[Test]
    public function renderBarRendersTrendWithContextForScreenReaders(): void
    {
        $metrics = [$this->metric('visitCount', ['trend' => '+21', 'trendDirection' => 'up'])];

        $xpath = $this->xpath($this->renderBar($metrics));

        $trend = $xpath->query('//span[contains(@class, "tx-analytics-performance-trend-up")]')->item(0);
        self::assertStringStartsWith('+21', trim($trend->textContent));
        self::assertSame(' compared to previous period', strtolower($xpath->query('.//span[@class="visually-hidden"]', $trend)->item(0)->textContent));
    }

    #[Test]
    public function renderBarRendersTrendNoteWithoutOwnTooltip(): void
    {
        $metrics = [
            $this->metric('visitCount', ['trend' => '+21', 'trendDirection' => 'up']),
            $this->metric('bounceRate', ['trendNote' => TrendNote::NoPreviousVisits->value]),
            $this->metric('averageVisitDuration', ['trendNote' => TrendNote::NoPreviousVisits->value]),
            $this->metric('continuationRate', ['trendNote' => TrendNote::NoPreviousVisits->value]),
        ];

        $html = $this->renderBar($metrics);

        // Three rates with a note: icon in the value row plus text in the tooltip and in the details panel.
        self::assertSame(3 * 3, substr_count($html, 'tx-analytics-performance-icon-circle-info'));
        self::assertSame(3 * 2, substr_count($html, 'tx-analytics-performance-tooltip-note'));
        self::assertStringNotContainsString('data-tx-analytics-tooltip', $html);
    }

    #[Test]
    public function renderBarRendersTheSparklineOfAMetricWithChart(): void
    {
        $metrics = [$this->metric('visitCount', ['chart' => [1, 4, 2], 'chartLabels' => ['a', 'b', 'c']])];

        $xpath = $this->xpath($this->renderBar($metrics));

        $charts = $xpath->query('//div[@class="tx-analytics-performance-tooltip-chart"]');
        self::assertSame(2, $charts->length);
        self::assertSame(1, $xpath->query('.//*[contains(@class, "tx-analytics-performance-sparkline")]', $charts->item(0))->length);
        self::assertSame(1, $xpath->query('.//*[local-name()="svg"]', $charts->item(0))->length);
    }

    #[Test]
    public function renderBarEscapesTheValues(): void
    {
        $metrics = [$this->metric('visitCount', ['value' => '<script>alert(1)</script>', 'details' => ['<b>1</b>', '-', '-']])];

        $html = $this->renderBar($metrics);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>1</b>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function renderBarKeepsTheModuleParametersInThePeriodForm(): void
    {
        $xpath = $this->xpath($this->subject->renderBar(5, 1, 30, [], ['languages' => [1], 'tx_analytics_period' => 7, 'id' => 9], ''));

        $fields = [];
        foreach ($xpath->query('//form//input[@type="hidden"]') as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        self::assertSame(['id' => '5', 'languages[0]' => '1'], $fields);
        self::assertSame('30', $xpath->query('//select/option[@selected]')->item(0)->getAttribute('value'));
    }

    #[Test]
    public function renderBarShowsTheDashboardLinkOnlyWithAUri(): void
    {
        $linkQuery = '//a[contains(@class, "tx-analytics-performance-meta-link")]';

        self::assertSame(0, $this->xpath($this->renderBar())->query($linkQuery)->length);
        self::assertSame(
            '/typo3/module/site/analytics/dashboard?siteIdentifier=main&days=7',
            $this->xpath($this->subject->renderBar(5, 0, 7, [], [], '/typo3/module/site/analytics/dashboard?siteIdentifier=main&days=7'))
                ->query($linkQuery)->item(0)->getAttribute('href')
        );
    }

    /**
     * @param list<array<string, mixed>>|null $metrics
     */
    private function renderBar(?array $metrics = null): string
    {
        $metrics ??= array_map(fn (string $key): array => $this->metric($key), ['visitCount', 'bounceRate', 'averageVisitDuration', 'continuationRate']);
        return $this->subject->renderBar(5, 0, 7, $metrics, [], '');
    }

    /**
     * A metric as PagePerformanceDataProvider::getMetrics() returns it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function metric(string $key, array $overrides = []): array
    {
        $labelKeys = [
            'visitCount' => 'views',
            'bounceRate' => 'bounceRate',
            'averageVisitDuration' => 'averageTimeOnPage',
            'continuationRate' => 'continuationRate',
        ];
        return array_merge([
            'key' => $key,
            'labelKey' => 'pagePerformance.' . $labelKeys[$key],
            'descriptionKey' => 'pagePerformance.tooltip.description.' . $labelKeys[$key],
            'trendContextKey' => 'dashboardWidget.sitePerformance.comparedToPreviousPeriod',
            'icon' => 'eye',
            'tone' => 'visits',
            'value' => '-',
            'trend' => null,
            'trendDirection' => null,
            'trendNote' => null,
            'details' => ['-', '-', '-'],
            'chart' => [],
            'chartLabels' => [],
            'chartLegend' => ['-', '-'],
        ], $overrides);
    }

    private function setDetailsExpanded(bool $expanded): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->uc = ['tx_analytics' => ['pagePerformanceExpanded' => $expanded ? '1' : '0']];
        $GLOBALS['BE_USER'] = $backendUser;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR);
        return new \DOMXPath($document);
    }

    private function querySelector(string $html, string $query): \DOMElement
    {
        $element = $this->xpath($html)->query($query)->item(0);
        self::assertInstanceOf(\DOMElement::class, $element);
        return $element;
    }
}
