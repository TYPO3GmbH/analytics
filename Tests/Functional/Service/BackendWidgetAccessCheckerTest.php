<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Service\BackendWidgetAccessCheckerInterface;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use T3G\Analytics\Tests\Functional\Fixtures\WidgetAccessUsers;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Uses real backend users and groups, so the permissions come from be_groups
 * and the widgets from the WidgetRegistry of the running TYPO3 version.
 */
final class BackendWidgetAccessCheckerTest extends FunctionalTestCase
{
    public static function widgetAccessDataProvider(): \Generator
    {
        $isTypo3V14 = (new Typo3Version())->getMajorVersion() >= 14;

        yield 'user without widget permissions' => [WidgetAccessUsers::WITHOUT_WIDGETS, 'analyticsTopPages', false];
        yield 'permission through the group' => [WidgetAccessUsers::TOP_PAGES_ONLY, 'analyticsTopPages', true];
        yield 'no permission for the widget' => [WidgetAccessUsers::TOP_PAGES_ONLY, 'analyticsTrafficGraph', false];
        // TYPO3 14 replaces this widget with four section variants, so the permission from TYPO3 13 grants no access.
        yield 'permission for the TYPO3 13 traffic sources widget' => [WidgetAccessUsers::V13_WIDGETS, 'analyticsTrafficSources', !$isTypo3V14];
        // TYPO3 13 does not register the section variants.
        yield 'permission for a TYPO3 14 section variant' => [WidgetAccessUsers::CHANNEL_ONLY, 'analyticsTrafficSourcesChannel', $isTypo3V14];
        yield 'admin' => [WidgetAccessUsers::ADMIN, 'analyticsTopPages', true];
        yield 'admin and widget that is not registered' => [WidgetAccessUsers::ADMIN, 'notRegistered', false];
    }

    #[Test]
    #[DataProvider('widgetAccessDataProvider')]
    public function userCanAccessWidgetUsesPermissionsAndRegisteredWidgets(int $backendUserId, string $widgetIdentifier, bool $expected): void
    {
        $this->importCSVDataSet(WidgetAccessUsers::CSV);
        $this->setUpBackendUser($backendUserId);

        self::assertSame($expected, $this->get(BackendWidgetAccessCheckerInterface::class)->userCanAccessWidget($widgetIdentifier));
    }

    #[Test]
    public function userCanAccessWidgetReturnsFalseWithoutBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertFalse($this->get(BackendWidgetAccessCheckerInterface::class)->userCanAccessWidget('analyticsTopPages'));
    }
}
