<?php

declare(strict_types=1);

namespace T3G\Analytics\Dashboard\Widget;

use T3G\Analytics\View\SparklineRenderer;
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

final readonly class TrafficGraphWidget implements WidgetInterface, AdditionalCssInterface, JavaScriptInterface
{
    private const DUMMY_VISITS_RESPONSE = [
        'payload' => [
            'labels' => [
                '2026-04-15T00:00:00+02:00',
                '2026-04-16T00:00:00+02:00',
                '2026-04-17T00:00:00+02:00',
                '2026-04-18T00:00:00+02:00',
                '2026-04-19T00:00:00+02:00',
                '2026-04-20T00:00:00+02:00',
                '2026-04-21T00:00:00+02:00',
                '2026-04-22T00:00:00+02:00',
                '2026-04-23T00:00:00+02:00',
                '2026-04-24T00:00:00+02:00',
                '2026-04-25T00:00:00+02:00',
                '2026-04-26T00:00:00+02:00',
                '2026-04-27T00:00:00+02:00',
                '2026-04-28T00:00:00+02:00',
                '2026-04-29T00:00:00+02:00',
                '2026-04-30T00:00:00+02:00',
                '2026-05-01T00:00:00+02:00',
                '2026-05-02T00:00:00+02:00',
                '2026-05-03T00:00:00+02:00',
                '2026-05-04T00:00:00+02:00',
                '2026-05-05T00:00:00+02:00',
                '2026-05-06T00:00:00+02:00',
                '2026-05-07T00:00:00+02:00',
                '2026-05-08T00:00:00+02:00',
                '2026-05-09T00:00:00+02:00',
                '2026-05-10T00:00:00+02:00',
                '2026-05-11T00:00:00+02:00',
                '2026-05-12T00:00:00+02:00',
            ],
            'datasets' => [
                [
                    'label' => 'Overall Visits',
                    'data' => [520, 610, 580, 640, 720, 690, 750, 800, 780, 840, 820, 880, 870, 935, 905, 965, 945, 1010, 990, 1055, 1025, 1085, 1065, 1145, 1125, 1205, 1285, 1355],
                    'total' => 26195,
                ],
            ],
        ],
    ];

    /** @var array{site?: string, refreshAvailable?: bool} */
    private array $options;

    /**
     * @param array{site?: string, refreshAvailable?: bool} $options
     */
    public function __construct(
        WidgetConfigurationInterface $configuration,
        private SparklineRenderer $sparklineRenderer,
        private SiteFinder $siteFinder,
        private ViewFactoryInterface $viewFactory,
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
            'EXT:analytics/Resources/Public/Css/Components/Sparkline.css',
            'EXT:analytics/Resources/Public/Css/TrafficGraph.css',
        ];
    }

    /**
     * @return list<JavaScriptModuleInstruction>
     */
    public function getJavaScriptModuleInstructions(): array
    {
        return [
            JavaScriptModuleInstruction::create('@t3g/analytics/traffic-graph-widget.js'),
        ];
    }

    private function renderContent(string $siteIdentifier): string
    {
        $uid = substr(sha1($siteIdentifier), 0, 8);
        $chart = $this->buildChartData();
        $siteOptions = $this->siteOptions($siteIdentifier);

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Partials')],
            layoutRootPaths: [GeneralUtility::getFileAbsFileName('EXT:analytics/Resources/Private/Layouts')],
        ));
        $view->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'analyseLabel' => $this->translate('dashboardWidget.trafficGraph.analyse'),
            'siteSelect' => [
                'id' => 'tx-analytics-traffic-graph-site-' . $uid,
                'label' => $this->translate('dashboardWidget.trafficGraph.setting.site.label'),
                'options' => $siteOptions,
                'visible' => count($siteOptions) > 1,
            ],
            'chart' => $chart,
        ]);

        return $view->render('Dashboard/Widget/TrafficGraph');
    }

    /**
     * @return array{sparkline: string, yLabels: list<array{value: int, label: string}>, xLabels: list<string>}
     */
    private function buildChartData(): array
    {
        $payload = $this->transformVisitsGraph(self::DUMMY_VISITS_RESPONSE['payload']);
        $data = $payload['datasets'][0]['data'];
        $scale = $payload['scale'];

        return [
            'sparkline' => $this->sparklineRenderer->render($data, [
                'label' => $this->translate('dashboardWidget.trafficGraph.chartLabel'),
                'class' => 'tx-analytics-traffic-graph-sparkline',
                'yMin' => $scale['min'],
                'yMax' => $scale['max'],
                'gridLines' => array_column($scale['ticks'], 'value'),
                'showLastPoint' => false,
                'preserveAspectRatio' => 'none',
            ]),
            'yLabels' => $scale['ticks'],
            'xLabels' => $this->visibleLabels($payload['labels']),
        ];
    }

    /**
     * @param array{
     *     labels?: list<string>,
     *     datasets?: list<array{label?: string, data?: list<int>, total?: int}>
     * } $apiPayload
     * @return array{
     *     labels: list<string>,
     *     datasets: list<array{label: string, data: list<int>}>,
     *     scale: array{min: int, max: int, ticks: list<array{value: int, label: string}>}
     * }
     */
    private function transformVisitsGraph(array $apiPayload): array
    {
        $labels = $apiPayload['labels'] ?? [];
        $datasets = $apiPayload['datasets'] ?? [];
        $data = $datasets[0]['data'] ?? [];

        $formattedLabels = array_map(
            static fn (string $iso): string => (new \DateTimeImmutable($iso))->format('j.n.'),
            $labels
        );

        $max = $data === [] ? 100 : max($data);
        $scaleMax = $this->calcScaleMax($max);
        $step = intdiv($scaleMax, 3);

        $ticks = [];
        for ($i = 3; $i >= 0; $i--) {
            $value = $step * $i;
            $ticks[] = [
                'value' => $value,
                'label' => $this->formatScaleLabel($value),
            ];
        }

        return [
            'labels' => $formattedLabels,
            'datasets' => [
                [
                    'label' => 'Visits',
                    'data' => $data,
                ],
            ],
            'scale' => [
                'min' => 0,
                'max' => $scaleMax,
                'ticks' => $ticks,
            ],
        ];
    }

    private function calcScaleMax(int $max): int
    {
        if ($max === 0) {
            return 100;
        }

        $magnitude = 10 ** (int)floor(log10($max));
        $nice = (int)(ceil($max / $magnitude) * $magnitude);

        return $nice < $max * 1.2 ? $nice * 2 : $nice;
    }

    private function formatScaleLabel(int $value): string
    {
        if ($value === 0) {
            return '0';
        }

        if ($value >= 1000) {
            return number_format($value / 1000, 1, '.', '') . 'k';
        }

        return (string)$value;
    }

    /**
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function siteOptions(string $selectedSiteIdentifier): array
    {
        $options = [
            [
                'value' => '',
                'label' => $this->translate('dashboardWidget.trafficGraph.setting.site.allSites'),
                'selected' => $selectedSiteIdentifier === '',
            ],
        ];
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $siteTitle = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
                $options[] = [
                    'value' => $site->getIdentifier(),
                    'label' => $siteTitle === ''
                        ? $site->getIdentifier()
                        : $siteTitle . ' (' . $site->getIdentifier() . ')',
                    'selected' => $site->getIdentifier() === $selectedSiteIdentifier,
                ];
            }
        } catch (\Throwable) {
            return $options;
        }

        return $options;
    }

    /**
     * @param list<string> $labels
     * @return list<string>
     */
    private function visibleLabels(array $labels): array
    {
        if ($labels === []) {
            return [];
        }

        $lastIndex = count($labels) - 1;
        $visibleIndexes = array_unique([
            0,
            (int)floor($lastIndex * 0.25),
            (int)floor($lastIndex * 0.5),
            (int)floor($lastIndex * 0.75),
            $lastIndex,
        ]);

        return array_values(array_intersect_key($labels, array_flip($visibleIndexes)));
    }

    private function translate(string $key): string
    {
        $label = $this->getLanguageService()->sL(
            'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:' . $key
        );

        return $label === '' ? $key : $label;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
