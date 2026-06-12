<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Service\TopPagesServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

/**
 * Top Pages dashboard widget for TYPO3 v14+.
 *
 * Site and period are configured via the native widget settings panel (WidgetRendererInterface)
 * rather than inline dropdowns. Requires TYPO3 >= 14 (WidgetRendererInterface).
 */
final readonly class TopPagesWidgetV14 implements WidgetRendererInterface, AdditionalCssInterface
{
    public function __construct(
        /** @phpstan-ignore property.onlyWritten (required by TYPO3 dashboard.widget DI compiler pass) */
        private WidgetConfigurationInterface $configuration,
        private SiteFinder $siteFinder,
        private TopPagesServiceInterface $topPagesService,
        private UriBuilder $uriBuilder,
        private ViewFactoryInterface $viewFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<SettingDefinition>
     */
    public function getSettingsDefinitions(): array
    {
        $siteOptions = $this->siteOptions();

        return [
            new SettingDefinition(
                key: 'site',
                type: 'string',
                default: array_key_first($siteOptions) ?? '',
                label: 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboardWidget.topPages.setting.site.label',
                enum: $siteOptions,
            ),
            new SettingDefinition(
                key: 'days',
                type: 'int',
                default: 7,
                label: 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:dashboardWidget.topPages.setting.period.label',
                enum: $this->periodOptions(),
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $siteIdentifier = (string)$context->settings->get('site');
        $days = max(1, (int)$context->settings->get('days'));

        $pages = $this->topPagesService->loadTopPagesData($siteIdentifier, $days);
        $trendLabel = $this->translate('dashboardWidget.topPages.comparedToPreviousPeriod');
        $pages = $pages !== null ? $this->topPagesService->buildPageItems($pages, $trendLabel) : [];

        $showAllUrl = $siteIdentifier !== ''
            ? (string)$this->uriBuilder->buildUriFromRoute('site_analytics.dashboard', ['siteIdentifier' => $siteIdentifier])
            : '';

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Partials')],
            layoutRootPaths: [
                GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Layouts'),
                GeneralUtility::getFileAbsFileName('EXT:dashboard/Resources/Private/Layouts'),
            ],
            request: $context->request,
        ));
        $view->assignMultiple([
            'pages' => $pages,
            'showAllUrl' => $showAllUrl,
            'showAllLabel' => $this->translate('dashboardWidget.topPages.showAll'),
        ]);

        return new WidgetResult(
            content: $view->render('Dashboard/Widget/TopPagesV14'),
            refreshable: true,
        );
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

    /**
     * @return array<int, string>
     */
    private function periodOptions(): array
    {
        $label = $this->translate('pagePerformance.days');
        $hasLabel = $label !== 'pagePerformance.days';
        $options = [];
        foreach ([7, 14, 30] as $days) {
            $options[$days] = $hasLabel ? sprintf($label, $days) : ($days . ' days');
        }
        return $options;
    }

    private function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof \TYPO3\CMS\Core\Localization\LanguageService) {
            return $key;
        }
        $label = $languageService->sL(
            'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:' . $key
        );
        return $label !== '' ? $label : $key;
    }
}
