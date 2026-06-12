<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Service\TopPagesServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
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
    /** @var array{site: string, days: int, refreshAvailable: bool} */
    private array $options;

    /**
     * @param array{site?: string, days?: int, refreshAvailable?: bool} $options
     */
    public function __construct(
        /** @phpstan-ignore property.onlyWritten (required by TYPO3 dashboard.widget DI compiler pass) */
        private WidgetConfigurationInterface $configuration,
        private SiteFinder $siteFinder,
        private TopPagesServiceInterface $topPagesService,
        private UriBuilder $uriBuilder,
        private ViewFactoryInterface $viewFactory,
        private LoggerInterface $logger,
        array $options = [],
    ) {
        $this->options = array_replace(
            [
                'site' => '',
                'days' => 7,
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
     * @return array{site: string, days: int, refreshAvailable: bool}
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
        if ($siteIdentifier === '' && $siteOptions !== []) {
            $siteIdentifier = array_key_first($siteOptions);
        }
        $days = max(1, (int)$this->options['days']);

        $pages = $this->topPagesService->loadTopPagesData($siteIdentifier, $days, 5);
        $trendLabel = $this->translate('dashboardWidget.topPages.comparedToPreviousPeriod');
        $pages = $pages !== null ? $this->topPagesService->buildPageItems($pages, $trendLabel) : [];

        $showAllUrl = $siteIdentifier !== ''
            ? (string)$this->uriBuilder->buildUriFromRoute('site_analytics.dashboard', ['siteIdentifier' => $siteIdentifier])
            : '';

        $uniqueId = substr(sha1((string)spl_object_id($this) . $siteIdentifier . implode('', array_keys($siteOptions))), 0, 8);

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
            'selectedDays' => $days,
            'siteSelectId' => 'tx-analytics-top-pages-site-' . $uniqueId,
            'siteLabel' => $this->translate('dashboardWidget.topPages.setting.site.label'),
            'showSiteSelect' => count($siteOptions) > 1,
            'siteOptions' => $this->buildSiteOptions($siteOptions, $siteIdentifier),
            'periodSelectId' => 'tx-analytics-top-pages-period-' . $uniqueId,
            'periodLabel' => $this->translate('dashboardWidget.topPages.setting.period.label'),
            'periodOptions' => $this->buildPeriodOptions($days),
            'showAllLabel' => $this->translate('dashboardWidget.topPages.showAll'),
            'showAllUrl' => $showAllUrl,
            'pages' => $pages,
        ]);

        return $view->render('Dashboard/Widget/TopPages');
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
     * @return list<array{value: int, label: string, selected: bool}>
     */
    private function buildPeriodOptions(int $selectedDays): array
    {
        $options = [];
        foreach ([7, 14, 30] as $period) {
            $options[] = [
                'value' => $period,
                'label' => sprintf($this->translate('pagePerformance.days'), $period),
                'selected' => $period === $selectedDays,
            ];
        }
        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function siteOptions(): array
    {
        $options = [];

        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                if (!$this->topPagesService->isAnalyticsSite($site)) {
                    continue;
                }
                if (!$this->topPagesService->userCanAccessPage($site->getRootPageId())) {
                    continue;
                }
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
        }

        return $options;
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
