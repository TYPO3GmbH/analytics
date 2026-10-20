<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Dashboard\WidgetRegistry;

readonly class BackendWidgetAccessChecker implements BackendWidgetAccessCheckerInterface
{
    public function __construct(
        private WidgetRegistry $widgetRegistry,
    ) {
    }

    /**
     * Same check as the dashboard: the widget is registered and the user has the
     * available_widgets permission. On TYPO3 14, an admin-only widget also requires an admin.
     * A permission for a widget that is not registered grants no access, for
     * example a permission from TYPO3 13 for a widget that TYPO3 14 replaces.
     * WidgetRegistry is marked internal, but the core has no public API for this check.
     */
    public function userCanAccessWidget(string $widgetIdentifier): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            // Fail closed: without an authenticated backend user, deny access.
            return false;
        }
        return array_key_exists($widgetIdentifier, $this->widgetRegistry->getAvailableWidgets());
    }
}
