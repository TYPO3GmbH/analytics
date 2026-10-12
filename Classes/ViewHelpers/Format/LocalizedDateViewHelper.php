<?php

declare(strict_types=1);

namespace T3G\Analytics\ViewHelpers\Format;

use T3G\Analytics\Service\MetricFormatterInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Formats a date in the locale of the backend user, like the dates in the charts.
 *
 * Usage: <analytics:format.localizedDate date="{site.status.expiresAt}" />
 *        <analytics:format.localizedDate date="{site.status._fetchedAt}" withTime="1" />
 */
final class LocalizedDateViewHelper extends AbstractViewHelper
{
    public function __construct(
        private readonly MetricFormatterInterface $formatter,
    ) {
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('date', 'mixed', 'DateTimeInterface, Unix timestamp or a string accepted by DateTimeImmutable', false, null);
        $this->registerArgument('withTime', 'bool', 'Adds the time to the date', false, false);
    }

    public function render(): string
    {
        $date = $this->arguments['date'] ?? $this->renderChildren();
        if ($date === null || $date === '') {
            return '';
        }
        try {
            $date = match (true) {
                $date instanceof \DateTimeInterface => $date,
                is_int($date) || (is_string($date) && ctype_digit($date)) => new \DateTimeImmutable('@' . $date),
                default => new \DateTimeImmutable((string)$date),
            };
        } catch (\Exception) {
            return '';
        }
        if ($date instanceof \DateTimeImmutable && $date->getTimezone()->getName() === '+00:00') {
            // A Unix timestamp has no timezone, so it is shown in the timezone of the installation.
            $date = $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        }
        return $this->arguments['withTime'] ? $this->formatter->formatDateTime($date) : $this->formatter->formatDate($date);
    }
}
