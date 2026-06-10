<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

final readonly class TopPagesWidget implements WidgetInterface, AdditionalCssInterface, JavaScriptInterface
{
    private const DUMMY_TOP_PAGES_RESPONSE = [
        'current' => [
            'payload' => [
                ['pageUrl' => 'https://example.com/', 'pageTitle' => 'Startseite', 'visitCount' => 8241, 'visitPercentOfTotal' => 32.4],
                ['pageUrl' => 'https://example.com/de/typo3-cms', 'pageTitle' => 'TYPO3 CMS – Übersi...', 'visitCount' => 5934, 'visitPercentOfTotal' => 23.3],
                ['pageUrl' => 'https://example.com/de/preise', 'pageTitle' => 'Preise & Pakete', 'visitCount' => 4512, 'visitPercentOfTotal' => 17.7],
                ['pageUrl' => 'https://example.com/de/kontakt', 'pageTitle' => 'Kontakt', 'visitCount' => 3128, 'visitPercentOfTotal' => 12.3],
                ['pageUrl' => 'https://example.com/de/blog-ki', 'pageTitle' => 'Blog – KI im CMS', 'visitCount' => 2307, 'visitPercentOfTotal' => 9.1],
                ['pageUrl' => 'https://example.com/de/referenzen', 'pageTitle' => 'Referenzen', 'visitCount' => 1648, 'visitPercentOfTotal' => 6.5],
                ['pageUrl' => 'https://example.com/de/agentur', 'pageTitle' => 'Agentur', 'visitCount' => 1326, 'visitPercentOfTotal' => 5.2],
                ['pageUrl' => 'https://example.com/de/support', 'pageTitle' => 'Support', 'visitCount' => 986, 'visitPercentOfTotal' => 3.9],
                ['pageUrl' => 'https://example.com/de/newsletter', 'pageTitle' => 'Newsletter', 'visitCount' => 742, 'visitPercentOfTotal' => 2.9],
                ['pageUrl' => 'https://example.com/de/impressum', 'pageTitle' => 'Impressum', 'visitCount' => 516, 'visitPercentOfTotal' => 2.0],
            ],
        ],
        'previous' => [
            'payload' => [
                ['pageUrl' => 'https://example.com/', 'pageTitle' => 'Startseite', 'visitCount' => 7924],
                ['pageUrl' => 'https://example.com/de/typo3-cms', 'pageTitle' => 'TYPO3 CMS – Übersi...', 'visitCount' => 5298],
                ['pageUrl' => 'https://example.com/de/preise', 'pageTitle' => 'Preise & Pakete', 'visitCount' => 4652],
                ['pageUrl' => 'https://example.com/de/kontakt', 'pageTitle' => 'Kontakt', 'visitCount' => 3128],
                ['pageUrl' => 'https://example.com/de/blog-ki', 'pageTitle' => 'Blog – KI im CMS', 'visitCount' => 1636],
                ['pageUrl' => 'https://example.com/de/referenzen', 'pageTitle' => 'Referenzen', 'visitCount' => 1715],
                ['pageUrl' => 'https://example.com/de/agentur', 'pageTitle' => 'Agentur', 'visitCount' => 1184],
                ['pageUrl' => 'https://example.com/de/support', 'pageTitle' => 'Support', 'visitCount' => 1039],
                ['pageUrl' => 'https://example.com/de/newsletter', 'pageTitle' => 'Newsletter', 'visitCount' => 604],
                ['pageUrl' => 'https://example.com/de/impressum', 'pageTitle' => 'Impressum', 'visitCount' => 516],
            ],
        ],
    ];

    /** @var array{site?: string, refreshAvailable?: bool} */
    private array $options;

    /**
     * @param array{site?: string, refreshAvailable?: bool} $options
     */
    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private SiteFinder $siteFinder,
        private ViewFactoryInterface $viewFactory,
        private LoggerInterface $logger,
        array $options = [],
    ) {
        $this->options = array_replace(
            [
                'site' => '',
                'refreshAvailable' => true,
            ],
            $options
        );
    }

    public function renderWidgetContent(): string
    {
        return $this->renderContent((string)$this->options['site']);
    }

    /**
     * @return array{site?: string, refreshAvailable?: bool}
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return [
            'EXT:analytics/Resources/Public/Css/TopPages.css',
        ];
    }

    /**
     * @return list<JavaScriptModuleInstruction>
     */
    public function getJavaScriptModuleInstructions(): array
    {
        return [
            JavaScriptModuleInstruction::create('@typo3/backend/element/progress-bar-element.js'),
            JavaScriptModuleInstruction::create('@t3g/analytics/top-pages-widget.js'),
        ];
    }

    private function renderContent(string $siteIdentifier): string
    {
        $siteOptions = $this->siteOptions();
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Partials')],
            layoutRootPaths: [
                GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Layouts'),
                GeneralUtility::getFileAbsFileName('EXT:dashboard/Resources/Private/Layouts'),
            ],
        ));
        $view->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'siteSelectId' => 'tx-analytics-top-pages-site-' . substr(sha1((string)spl_object_id($this) . $siteIdentifier . implode('', array_keys($siteOptions))), 0, 8),
            'siteLabel' => $this->translate('dashboardWidget.topPages.setting.site.label'),
            'showSiteSelect' => count($siteOptions) > 1,
            'siteOptions' => $this->buildSiteOptions($siteOptions, $siteIdentifier),
            'showAllLabel' => $this->translate('dashboardWidget.topPages.showAll'),
            'pages' => $this->buildPageItems(self::DUMMY_TOP_PAGES_RESPONSE),
        ]);

        return $view->render('Dashboard/Widget/TopPages');
    }

    /**
     * @param array{
     *     current: array{payload: list<array{pageUrl: string, pageTitle: string, visitCount: int, visitPercentOfTotal: float}>},
     *     previous: array{payload: list<array{pageUrl: string, pageTitle: string, visitCount: int}>}
     * } $response
     * @return list<array{position: int, url: string, title: string, visitCount: string, visitPercentOfTotal: string, trend: string, trendDirection: string, trendLabel: string}>
     */
    private function buildPageItems(array $response): array
    {
        $previousByUrl = [];
        foreach ($response['previous']['payload'] ?? [] as $previousPage) {
            $previousByUrl[$previousPage['pageUrl']] = $previousPage['visitCount'];
        }

        $trendLabel = $this->translate('dashboardWidget.topPages.comparedToPreviousPeriod');
        $items = [];
        foreach ($response['current']['payload'] as $index => $page) {
            $previousVisitCount = $previousByUrl[$page['pageUrl']] ?? 0;
            $trend = $this->formatRelativeTrend((float)$page['visitCount'], (float)$previousVisitCount);
            $items[] = [
                'position' => $index + 1,
                'url' => $page['pageUrl'],
                'title' => $page['pageTitle'],
                'visitCount' => $this->formatNumber($page['visitCount']),
                'visitPercentOfTotal' => $this->formatPercentageWidth($page['visitPercentOfTotal']),
                'trend' => $trend,
                'trendDirection' => $trend !== '' ? $this->trendDirection((float)$page['visitCount'], (float)$previousVisitCount) : 'neutral',
                'trendLabel' => $trendLabel,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, string> $siteOptions
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function buildSiteOptions(array $siteOptions, string $siteIdentifier): array
    {
        $options = [];
        foreach ($siteOptions as $identifier => $label) {
            $options[] = [
                'value' => $identifier,
                'label' => $label,
                'selected' => $identifier === $siteIdentifier,
            ];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function siteOptions(): array
    {
        $options = [
            '' => $this->translate('dashboardWidget.topPages.setting.site.allSites'),
        ];

        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $siteTitle = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
                $options[$site->getIdentifier()] = $siteTitle === ''
                    ? $site->getIdentifier()
                    : $siteTitle . ' (' . $site->getIdentifier() . ')';
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load sites for top pages widget: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            return $options;
        }

        return $options;
    }

    private function formatNumber(int $value): string
    {
        return number_format($value, 0, '.', '.');
    }

    private function formatPercentageWidth(float $value): string
    {
        return rtrim(rtrim(number_format(max(0.0, min(100.0, $value)), 1, '.', ''), '0'), '.');
    }

    private function formatRelativeTrend(float $currentValue, float $previousValue): string
    {
        if ($previousValue === 0.0) {
            return '';
        }

        $formatted = $this->formatSignedNumber((($currentValue - $previousValue) / $previousValue) * 100);

        return $formatted === '±0' ? '' : $formatted . '%';
    }

    private function trendDirection(float $currentValue, float $previousValue): string
    {
        if ($previousValue === 0.0 || $currentValue === $previousValue) {
            return 'neutral';
        }

        return $currentValue > $previousValue ? 'up' : 'down';
    }

    private function formatSignedNumber(float $value): string
    {
        $formattedValue = rtrim(rtrim(number_format(abs($value), 1, '.', ''), '0'), '.');

        if ($formattedValue === '0') {
            return '±0';
        }

        return ($value >= 0 ? '+' : '-') . $formattedValue;
    }

    private function translate(string $key): string
    {
        $languageService = $this->getLanguageService();
        if ($languageService === null) {
            return $key;
        }

        $label = $languageService->sL(
            'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:' . $key
        );

        return $label === '' ? $key : $label;
    }

    private function getLanguageService(): ?LanguageService
    {
        return $GLOBALS['LANG'] ?? null;
    }
}
