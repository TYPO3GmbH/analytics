<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

/**
 * Why a metric shows no trend. The value is the suffix of the label key "trendNote.*".
 */
enum TrendNote: string
{
    /** The previous period had no visits, so a rate (bounce rate, time on page) has no value to compare with. */
    case NoPreviousVisits = 'noPreviousVisits';

    /** The values of the previous period could not be loaded. */
    case PreviousUnavailable = 'previousUnavailable';
}
