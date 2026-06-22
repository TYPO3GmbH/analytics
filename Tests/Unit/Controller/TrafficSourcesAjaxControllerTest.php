<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Controller\TrafficSourcesAjaxController;
use T3G\Analytics\Service\AnalyticsSiteProviderInterface;
use T3G\Analytics\Service\MetricFormatter;
use T3G\Analytics\Service\TrafficSourcesServiceInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TrafficSourcesAjaxControllerTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private TrafficSourcesAjaxController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new TrafficSourcesAjaxController(
            $this->createMock(TrafficSourcesServiceInterface::class),
            $this->createMock(AnalyticsSiteProviderInterface::class),
            new MetricFormatter(),
            $this->createMock(UriBuilder::class),
            $this->createMock(ViewFactoryInterface::class),
        );
    }

    /** buildDeviceItems — change computation */

    #[Test]
    public function buildDeviceItemsComputesPositiveChangeFromPreviousSessionCount(): void
    {
        $payload = [
            ['deviceType' => 'desktop', 'sessionCount' => 80, 'sessionPercentOfTotal' => 80.0, 'previousSessionCount' => 40],
        ];

        $items = $this->callBuildDeviceItems($payload);

        self::assertSame('+100.00%', $items[0]['change']);
    }

    #[Test]
    public function buildDeviceItemsComputesNegativeChangeFromPreviousSessionCount(): void
    {
        $payload = [
            ['deviceType' => 'mobile', 'sessionCount' => 20, 'sessionPercentOfTotal' => 20.0, 'previousSessionCount' => 60],
        ];

        $items = $this->callBuildDeviceItems($payload);

        self::assertSame('-66.67%', $items[0]['change']);
    }

    #[Test]
    public function buildDeviceItemsShowsInfinityChangeWhenNoPreviousSessionCount(): void
    {
        $payload = [
            ['deviceType' => 'desktop', 'sessionCount' => 80, 'sessionPercentOfTotal' => 80.0],
        ];

        $items = $this->callBuildDeviceItems($payload);

        self::assertSame('+∞%', $items[0]['change']);
    }

    /** buildDeviceItems — changeTone */

    #[Test]
    public function buildDeviceItemsHasPositiveChangeToneWhenCurrentExceedsPrevious(): void
    {
        $payload = [
            ['deviceType' => 'desktop', 'sessionCount' => 80, 'sessionPercentOfTotal' => 80.0, 'previousSessionCount' => 40],
        ];

        $items = $this->callBuildDeviceItems($payload);

        self::assertSame('positive', $items[0]['changeTone']);
    }

    #[Test]
    public function buildDeviceItemsHasNegativeChangeToneWhenCurrentBelowPrevious(): void
    {
        $payload = [
            ['deviceType' => 'desktop', 'sessionCount' => 40, 'sessionPercentOfTotal' => 40.0, 'previousSessionCount' => 80],
        ];

        $items = $this->callBuildDeviceItems($payload);

        self::assertSame('negative', $items[0]['changeTone']);
    }

    #[Test]
    public function buildDeviceItemsHasPositiveChangeToneWhenNoPreviousSessionCount(): void
    {
        $payload = [
            ['deviceType' => 'desktop', 'sessionCount' => 80, 'sessionPercentOfTotal' => 80.0],
        ];

        $items = $this->callBuildDeviceItems($payload);

        self::assertSame('positive', $items[0]['changeTone']);
    }

    /** buildDeviceItems — share value */

    #[Test]
    public function buildDeviceItemsComputesShareFromTotalSessions(): void
    {
        $payload = [
            ['deviceType' => 'desktop', 'sessionCount' => 75, 'sessionPercentOfTotal' => 75.0],
            ['deviceType' => 'mobile', 'sessionCount' => 25, 'sessionPercentOfTotal' => 25.0],
        ];

        $items = $this->callBuildDeviceItems($payload);

        self::assertSame('75.00', $items[0]['value']);
        self::assertSame('25.00', $items[1]['value']);
    }

    /** buildBrowserItems */

    #[Test]
    public function buildBrowserItemsUsesBrowserNameAsLabel(): void
    {
        $payload = [
            ['browserName' => 'Chrome', 'sessionCount' => 60, 'sessionPercentOfTotal' => 60.0, 'previousSessionCount' => 50],
        ];

        $items = $this->callBuildBrowserItems($payload);

        self::assertSame('Chrome', $items[0]['label']);
        self::assertSame('+20.00%', $items[0]['change']);
        self::assertSame('positive', $items[0]['changeTone']);
    }

    #[Test]
    public function buildBrowserItemsShowsInfinityChangeWhenNoPreviousData(): void
    {
        $payload = [
            ['browserName' => 'Firefox', 'sessionCount' => 40, 'sessionPercentOfTotal' => 40.0],
        ];

        $items = $this->callBuildBrowserItems($payload);

        self::assertSame('+∞%', $items[0]['change']);
        self::assertSame('positive', $items[0]['changeTone']);
    }

    /** buildCountryItems */

    #[Test]
    public function buildCountryItemsComputesChangeFromPreviousSessionCount(): void
    {
        $payload = [
            ['countryCode' => 'de', 'sessionCount' => 100, 'sessionPercentOfTotal' => 100.0, 'previousSessionCount' => 50],
        ];

        $items = $this->callBuildCountryItems($payload);

        self::assertSame('+100.00%', $items[0]['change']);
        self::assertSame('positive', $items[0]['changeTone']);
    }

    #[Test]
    public function buildCountryItemsShowsInfinityChangeWhenNoPreviousData(): void
    {
        $payload = [
            ['countryCode' => 'de', 'sessionCount' => 100, 'sessionPercentOfTotal' => 100.0],
        ];

        $items = $this->callBuildCountryItems($payload);

        self::assertSame('+∞%', $items[0]['change']);
        self::assertSame('positive', $items[0]['changeTone']);
    }

    /** buildTrafficSourceItems */

    #[Test]
    public function buildTrafficSourceItemsComputesShareFromTotal(): void
    {
        $sources = ['direct' => ['current' => 70, 'previous' => 0], 'search' => ['current' => 30, 'previous' => 0]];

        $items = $this->callBuildTrafficSourceItems($sources);

        self::assertSame('70.00', $items[0]['value']);
        self::assertSame('30.00', $items[1]['value']);
    }

    #[Test]
    public function buildTrafficSourceItemsAssignsToneByChannel(): void
    {
        $sources = [
            'direct' => ['current' => 60, 'previous' => 0],
            'search' => ['current' => 30, 'previous' => 0],
            'social' => ['current' => 10, 'previous' => 0],
        ];

        $items = $this->callBuildTrafficSourceItems($sources);

        self::assertSame('green', $items[0]['tone']);
        self::assertSame('blue', $items[1]['tone']);
        self::assertSame('yellow', $items[2]['tone']);
    }

    #[Test]
    public function buildTrafficSourceItemsShowsChangeWhenPreviousDataAvailable(): void
    {
        $sources = ['direct' => ['current' => 100, 'previous' => 50]];

        $items = $this->callBuildTrafficSourceItems($sources);

        self::assertSame('+100.00%', $items[0]['change']);
        self::assertSame('positive', $items[0]['changeTone']);
    }

    #[Test]
    public function buildTrafficSourceItemsShowsInfinityWhenPreviousIsZeroAndCurrentIsPositive(): void
    {
        $sources = ['direct' => ['current' => 5, 'previous' => 0]];

        $items = $this->callBuildTrafficSourceItems($sources);

        self::assertSame('+∞%', $items[0]['change']);
        self::assertSame('positive', $items[0]['changeTone']);
    }

    #[Test]
    public function buildTrafficSourceItemsHasNullChangeWhenBothPeriodsAreZero(): void
    {
        $sources = ['direct' => ['current' => 0, 'previous' => 0]];

        $items = $this->callBuildTrafficSourceItems($sources);

        self::assertNull($items[0]['change']);
        self::assertSame('', $items[0]['changeTone']);
    }

    /**
     * @param list<array{deviceType: string, sessionCount: int, sessionPercentOfTotal: int|float, previousSessionCount?: int}> $payload
     * @return list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>
     */
    private function callBuildDeviceItems(array $payload): array
    {
        /** @var list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}> */
        return (new \ReflectionMethod($this->subject, 'buildDeviceItems'))->invoke($this->subject, $payload);
    }

    /**
     * @param list<array{browserName: string, sessionCount: int, sessionPercentOfTotal: int|float, previousSessionCount?: int}> $payload
     * @return list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>
     */
    private function callBuildBrowserItems(array $payload): array
    {
        /** @var list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}> */
        return (new \ReflectionMethod($this->subject, 'buildBrowserItems'))->invoke($this->subject, $payload);
    }

    /**
     * @param list<array{countryCode: string, sessionCount: int, sessionPercentOfTotal: int|float, previousSessionCount?: int}> $payload
     * @return list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>
     */
    private function callBuildCountryItems(array $payload): array
    {
        /** @var list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}> */
        return (new \ReflectionMethod($this->subject, 'buildCountryItems'))->invoke($this->subject, $payload);
    }

    /**
     * @param array<string, array{current: int, previous: int}> $sources
     * @return list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}>
     */
    private function callBuildTrafficSourceItems(array $sources): array
    {
        /** @var list<array{label: string, value: string, tone: string, icon: string, change: string|null, changeTone: string}> */
        return (new \ReflectionMethod($this->subject, 'buildTrafficSourceItems'))->invoke($this->subject, $sources);
    }
}
