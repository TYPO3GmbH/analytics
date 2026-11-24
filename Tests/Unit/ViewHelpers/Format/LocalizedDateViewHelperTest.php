<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\ViewHelpers\Format;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Service\MetricFormatter;
use T3G\Analytics\ViewHelpers\Format\LocalizedDateViewHelper;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LocalizedDateViewHelperTest extends UnitTestCase
{
    private string $defaultTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('getLocale')->willReturn(new Locale('de'));
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        date_default_timezone_set($this->defaultTimezone);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function dateProvider(): iterable
    {
        yield 'DateTimeImmutable' => [new \DateTimeImmutable('2026-10-02 14:05:00'), '02.10.2026'];
        yield 'ISO string' => ['2026-10-02T14:05:00+02:00', '02.10.2026'];
        // 1789250400 is 2026-09-12 22:00 UTC, which is already 2026-09-13 in the timezone of the installation.
        yield 'Unix timestamp' => [1789250400, '13.09.2026'];
        yield 'Unix timestamp as string' => ['1789250400', '13.09.2026'];
    }

    #[Test]
    #[DataProvider('dateProvider')]
    public function rendersTheDateInTheLocaleOfTheBackendUser(mixed $date, string $expected): void
    {
        self::assertSame($expected, $this->render(['date' => $date, 'withTime' => false]));
    }

    #[Test]
    public function rendersTheTimeWithWithTime(): void
    {
        self::assertSame('02.10.2026, 14:05', $this->render(['date' => '2026-10-02T14:05:00+02:00', 'withTime' => true]));
    }

    #[Test]
    public function rendersTheDateFromTheChildren(): void
    {
        self::assertSame('02.10.2026', $this->render(['date' => null, 'withTime' => false], '2026-10-02'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptyDateProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'invalid string' => ['not a date'];
    }

    #[Test]
    #[DataProvider('emptyDateProvider')]
    public function rendersNothingWithoutAValidDate(mixed $date): void
    {
        self::assertSame('', $this->render(['date' => $date, 'withTime' => false]));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function render(array $arguments, mixed $children = null): string
    {
        $subject = new LocalizedDateViewHelper(new MetricFormatter());
        $subject->setArguments($arguments);
        $subject->setRenderChildrenClosure(static fn (): mixed => $children);
        return $subject->render();
    }
}
