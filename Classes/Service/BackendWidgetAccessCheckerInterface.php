<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface BackendWidgetAccessCheckerInterface
{
    public function userCanAccessWidget(string $widgetIdentifier): bool;
}
