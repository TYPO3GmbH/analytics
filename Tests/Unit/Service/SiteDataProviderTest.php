<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Service\SiteDataProvider;
use T3G\Analytics\Service\AnalyticsStatusService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SiteDataProviderTest extends UnitTestCase
{
    private SiteDataProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new SiteDataProvider(
            $this->createMock(SiteFinder::class),
            $this->createMock(AnalyticsStatusService::class),
            $this->createMock(ConnectionPool::class),
            $this->createMock(UriBuilder::class),
        );
    }

    /** siteLabel */

    #[Test]
    public function siteLabelReturnsSiteIdentifierWhenPageNameIsEmpty(): void
    {
        self::assertSame('main', $this->subject->siteLabel('', 'main'));
    }

    #[Test]
    public function siteLabelReturnsSiteIdentifierWhenPageNameIsWhitespaceOnly(): void
    {
        self::assertSame('main', $this->subject->siteLabel('   ', 'main'));
    }

    #[Test]
    public function siteLabelReturnsCombinedLabelWhenPageNameIsSet(): void
    {
        self::assertSame('Home (main)', $this->subject->siteLabel('Home', 'main'));
    }

    /** resolvePanelClass */

    #[Test]
    public function resolvePanelClassReturnsPanelDefaultWhenStatusIsNull(): void
    {
        self::assertSame('panel-default', $this->subject->resolvePanelClass(null));
    }

    #[Test]
    public function resolvePanelClassReturnsPanelDefaultWhenStatusHasNoConsumption(): void
    {
        self::assertSame('panel-default', $this->subject->resolvePanelClass(['trackingId' => 'abc']));
    }

    #[Test]
    public function resolvePanelClassReturnsPanelDangerWhenLimitedAndExhausted(): void
    {
        $status = ['consumption' => ['limited' => true, 'exhausted' => true, 'warning' => false]];
        self::assertSame('panel-danger', $this->subject->resolvePanelClass($status));
    }

    #[Test]
    public function resolvePanelClassReturnsPanelWarningWhenWarningIsSet(): void
    {
        $status = ['consumption' => ['limited' => false, 'exhausted' => false, 'warning' => true]];
        self::assertSame('panel-warning', $this->subject->resolvePanelClass($status));
    }

    #[Test]
    public function resolvePanelClassReturnsPanelDefaultWhenConsumptionIsHealthy(): void
    {
        $status = ['consumption' => ['limited' => false, 'exhausted' => false, 'warning' => false]];
        self::assertSame('panel-default', $this->subject->resolvePanelClass($status));
    }

    #[Test]
    public function resolvePanelClassReturnsPanelDefaultWhenOnlyLimitedWithoutExhausted(): void
    {
        $status = ['consumption' => ['limited' => true, 'exhausted' => false, 'warning' => false]];
        self::assertSame('panel-default', $this->subject->resolvePanelClass($status));
    }
}
