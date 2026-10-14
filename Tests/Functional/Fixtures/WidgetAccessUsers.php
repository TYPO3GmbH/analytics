<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Fixtures;

/**
 * Backend users in widget_access.csv. The groups grant the widgets in the
 * be_groups.availableWidgets field, as TYPO3 does.
 */
final class WidgetAccessUsers
{
    public const CSV = __DIR__ . '/widget_access.csv';

    public const WITHOUT_WIDGETS = 1;
    /** All widgets of TYPO3 13, also the traffic sources widget that TYPO3 14 replaces. */
    public const V13_WIDGETS = 2;
    public const TOP_PAGES_ONLY = 3;
    /** Only the TYPO3 14 section variant analyticsTrafficSourcesChannel. */
    public const CHANNEL_ONLY = 4;
    public const ADMIN = 5;
    public const TRAFFIC_GRAPH_ONLY = 6;
}
