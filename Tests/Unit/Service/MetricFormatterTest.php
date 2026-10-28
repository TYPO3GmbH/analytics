<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Service\MetricFormatter;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MetricFormatterTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'German' => ['de', '02.10.2026', '02.10.2026, 14:05', '02.10.'];
        yield 'English' => ['en', 'Oct 2, 2026', 'Oct 2, 2026, 2:05 PM', '10/02'];
    }

    #[Test]
    #[DataProvider('localeProvider')]
    public function formatsDatesInTheLocaleOfTheBackendUser(string $locale, string $date, string $dateTime, string $dayMonth): void
    {
        $this->setBackendLocale($locale);
        $subject = new MetricFormatter();
        $value = new \DateTimeImmutable('2026-10-02 14:05:00', new \DateTimeZone('Europe/Berlin'));

        self::assertSame($date, $subject->formatDate($value));
        // ICU may use a narrow no-break space before "PM"; compare with normal spaces.
        self::assertSame($dateTime, preg_replace('/\h/u', ' ', $subject->formatDateTime($value)));
        self::assertSame($dayMonth, $subject->formatDayMonth($value));
    }

    #[Test]
    public function fallsBackToEnglishWithoutBackendUser(): void
    {
        self::assertSame('Oct 2, 2026', (new MetricFormatter())->formatDate(new \DateTimeImmutable('2026-10-02')));
    }

    #[Test]
    public function keepsTheDayOfTheDateTimezone(): void
    {
        $this->setBackendLocale('de');

        // 22:00 UTC is already the next day in Berlin; the date must stay the UTC day it was created for.
        // The API returns "Z" and offsets, which ICU does not accept as they come from ext/date.
        self::assertSame('12.09.2026', (new MetricFormatter())->formatDate(new \DateTimeImmutable('2026-09-12T22:00:00Z')));
        self::assertSame('13.09.2026', (new MetricFormatter())->formatDate(new \DateTimeImmutable('2026-09-13T00:00:00+02:00')));
        self::assertSame('12.09.2026', (new MetricFormatter())->formatDate(new \DateTimeImmutable('@1789250400')));
    }

    #[Test]
    public function showsNeutralTrendsForUnchangedValues(): void
    {
        $subject = new MetricFormatter();

        self::assertSame('±0', $subject->formatAbsoluteCountTrend(5, 5));
        self::assertSame('±0.00%', $subject->formatAbsolutePercentPointTrend(40.0, 40.0));
        self::assertSame('±0s', $subject->formatAbsoluteDurationTrend(60, 60));
    }

    #[Test]
    public function showsTrendsFromZero(): void
    {
        $subject = new MetricFormatter();

        self::assertSame('+2', $subject->formatAbsoluteCountTrend(2, 0));
        self::assertSame('+50.00%', $subject->formatAbsolutePercentPointTrend(50.0, 0.0));
    }

    private function setBackendLocale(string $locale): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('getLocale')->willReturn(new Locale($locale));
        $GLOBALS['LANG'] = $languageService;
    }
}
