<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Dashboard\Widget;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Dashboard\Widget\TopPagesWidgetV14;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\TopPagesServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Settings\SettingsInterface;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TopPagesWidgetV14Test extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private WidgetConfigurationInterface&MockObject $configuration;
    private TopPagesServiceInterface&MockObject $topPagesService;
    private AnalyticsSiteProviderInterface&MockObject $siteProvider;
    private UriBuilder&MockObject $uriBuilder;
    private ViewFactoryInterface&MockObject $viewFactory;
    private ViewInterface&MockObject $view;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!interface_exists(WidgetConfigurationInterface::class)) {
            $candidates = [
                dirname(__DIR__, 4) . '/vendor/typo3/cms-dashboard/Classes/Widgets/',
                dirname(__DIR__, 4) . '/.Build/dummy-typo3/vendor/typo3/cms-dashboard/Classes/Widgets/',
            ];
            foreach ($candidates as $dashboardPath) {
                if (!is_dir($dashboardPath)) {
                    continue;
                }
                foreach (['WidgetConfigurationInterface.php', 'WidgetInterface.php', 'AdditionalCssInterface.php', 'JavaScriptInterface.php'] as $file) {
                    $full = $dashboardPath . $file;
                    if (file_exists($full)) {
                        require_once $full;
                    }
                }
                break;
            }
        }

        require_once dirname(__DIR__, 4) . '/Build/phpstan.bootstrap-v14stubs.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration = $this->createMock(WidgetConfigurationInterface::class);
        $this->configuration->method('getIdentifier')->willReturn('analyticsTopPagesV14');

        $this->topPagesService = $this->createMock(TopPagesServiceInterface::class);
        $this->topPagesService->method('loadTopPagesData')->willReturn(null);

        $this->siteProvider = $this->createMock(AnalyticsSiteProviderInterface::class);

        $mockUri = $this->createMock(\Psr\Http\Message\UriInterface::class);
        $mockUri->method('__toString')->willReturn('');
        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->uriBuilder->method('buildUriFromRoute')->willReturn($mockUri);

        $this->view = $this->createMock(ViewInterface::class);
        $this->view->method('assignMultiple')->willReturnSelf();
        $this->view->method('render')->willReturn('<div>top-pages-html</div>');

        $this->viewFactory = $this->createMock(ViewFactoryInterface::class);
        $this->viewFactory->method('create')->willReturn($this->view);
    }

    private function makeWidget(): TopPagesWidgetV14
    {
        return new TopPagesWidgetV14(
            $this->configuration,
            $this->topPagesService,
            $this->siteProvider,
            $this->uriBuilder,
            $this->viewFactory,
        );
    }

    private function makeSettings(string $site = 'demo', int $days = 7, int $limit = 10, string $title = '', bool $showMeta = true): SettingsInterface
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('get')->willReturnMap([
            ['site', $site],
            ['days', $days],
            ['limit', $limit],
            ['title', $title],
            ['showMeta', $showMeta],
        ]);
        return $settings;
    }

    private function makeContext(SettingsInterface $settings): WidgetContext
    {
        // WidgetContext became a final readonly value object in TYPO3 v14.
        if ((new Typo3Version())->getMajorVersion() >= 14) {
            return new WidgetContext(
                identifier: 'test',
                rawData: [],
                configuration: $this->configuration,
                settings: $settings,
                request: $this->createMock(ServerRequestInterface::class),
            );
        }
        $context = new WidgetContext(); // @phpstan-ignore argument.count
        $context->settings = $settings;
        $context->request = $this->createMock(ServerRequestInterface::class);
        return $context;
    }

    #[Test]
    public function getSettingsDefinitionsContainsExpectedKeys(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site']);

        $definitions = $this->makeWidget()->getSettingsDefinitions();
        $keys = array_map(static fn (SettingDefinition $d) => $d->key, $definitions);

        self::assertContains('site', $keys);
        self::assertContains('days', $keys);
        self::assertContains('limit', $keys);
        self::assertContains('title', $keys);
        self::assertContains('showMeta', $keys);
    }

    #[Test]
    public function renderWidgetReturnsWidgetResultInstance(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings()));

        self::assertInstanceOf(WidgetResult::class, $result);
    }

    #[Test]
    public function renderWidgetLabelIncludesMetaWhenShowMetaIsTrue(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings(
            site: 'demo',
            days: 7,
            showMeta: true,
        )));

        self::assertSame('dashboardWidget.topPages.title (Demo Site · 7 days)', $result->label);
    }

    #[Test]
    public function renderWidgetLabelExcludesMetaWhenShowMetaIsFalse(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings(
            site: 'demo',
            days: 7,
            showMeta: false,
        )));

        self::assertSame('dashboardWidget.topPages.title', $result->label);
    }

    #[Test]
    public function renderWidgetUsesCustomTitleAsBaseWhenSet(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings(
            site: 'demo',
            title: 'My Custom Title',
            showMeta: false,
        )));

        self::assertSame('My Custom Title', $result->label);
    }

    #[Test]
    public function renderWidgetCombinesCustomTitleWithMetaWhenShowMetaIsTrue(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['demo' => 'Demo Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings(
            site: 'demo',
            days: 7,
            title: 'My Custom Title',
            showMeta: true,
        )));

        self::assertSame('My Custom Title (Demo Site · 7 days)', $result->label);
    }

    #[Test]
    public function renderWidgetFallsBackToSiteIdentifierWhenNotInSiteOptions(): void
    {
        $this->siteProvider->method('siteOptions')->willReturn(['other' => 'Other Site']);

        $result = $this->makeWidget()->renderWidget($this->makeContext($this->makeSettings(
            site: 'unknown-site',
            days: 7,
            showMeta: true,
        )));

        self::assertStringContainsString('unknown-site', $result->label ?? '');
    }
}
