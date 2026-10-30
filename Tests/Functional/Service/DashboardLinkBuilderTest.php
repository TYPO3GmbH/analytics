<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Service\DashboardLinkBuilderInterface;
use T3G\Analytics\Tests\Fixtures\AnalyticsIds;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;

/**
 * Builds dashboard links with real site configurations, real backend users and the real
 * page access check from Fixtures/dashboard_link.csv.
 *
 *   - site-main:     active (root page 1)
 *   - site-attached: attached to site-main (root page 2)
 *   - site-orphan:   attached to a site that does not exist (root page 3)
 */
final class DashboardLinkBuilderTest extends FunctionalTestCase
{
    private const EDITOR = 1;
    private const EDITOR_MAIN_ONLY = 2;
    private const EDITOR_WITHOUT_MAIN = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/dashboard_link.csv');
        $this->writeSite('site-main', 1, ['websiteId' => AnalyticsIds::WEBSITE_ID]);
        $this->writeSite('site-attached', 2, ['websiteId' => AnalyticsIds::WEBSITE_ID, 'attachedTo' => 'site-main']);
        $this->writeSite('site-orphan', 3, ['websiteId' => AnalyticsIds::WEBSITE_ID, 'attachedTo' => 'site-removed']);
    }

    /** build */

    #[Test]
    public function buildReturnsTheDashboardLinkOfTheSite(): void
    {
        $this->setUpBackendUser(self::EDITOR_MAIN_ONLY);

        $link = $this->subject()->build('site-main', ['days' => 7]);

        self::assertStringContainsString('/module/site/analytics/dashboard', $link);
        self::assertSame(['siteIdentifier' => 'site-main', 'days' => '7'], $this->linkParameters($link));
    }

    #[Test]
    public function buildReturnsNothingForAnAttachedSite(): void
    {
        // The dashboard cannot filter by the domain of the attached site.
        $this->setUpBackendUser(self::EDITOR);

        self::assertSame('', $this->subject()->build('site-attached', ['days' => 7]));
    }

    #[Test]
    public function buildReturnsNothingWithoutAccessToTheSite(): void
    {
        $this->setUpBackendUser(self::EDITOR_WITHOUT_MAIN);

        self::assertSame('', $this->subject()->build('site-main'));
    }

    #[Test]
    public function buildReturnsNothingForAnUnknownOrEmptySite(): void
    {
        $this->setUpBackendUser(self::EDITOR);

        self::assertSame('', $this->subject()->build('unknown'));
        self::assertSame('', $this->subject()->build(''));
    }

    /** buildForPage */

    #[Test]
    public function buildForPageReturnsTheDashboardLinkOfTheSiteWithThePageFilter(): void
    {
        $this->setUpBackendUser(self::EDITOR_MAIN_ONLY);

        $link = $this->subject()->buildForPage('site-main', 'https://site-main.example.com/about', ['days' => 7]);

        self::assertSame(
            ['siteIdentifier' => 'site-main', 'days' => '7', 'pageUrl' => 'https://site-main.example.com/about'],
            $this->linkParameters($link)
        );
    }

    #[Test]
    public function buildForPageReturnsTheDashboardLinkOfTheActiveSiteForAnAttachedSite(): void
    {
        $this->setUpBackendUser(self::EDITOR);

        $link = $this->subject()->buildForPage('site-attached', 'https://site-attached.example.com/about', ['days' => 7]);

        self::assertSame(
            ['siteIdentifier' => 'site-main', 'days' => '7', 'pageUrl' => 'https://site-attached.example.com/about'],
            $this->linkParameters($link)
        );
    }

    #[Test]
    public function buildForPageReturnsNothingForAnAttachedSiteWithoutAccessToTheActiveSite(): void
    {
        // The dashboard of the active site shows all its data, so access to the attached site is not enough.
        $this->setUpBackendUser(self::EDITOR_WITHOUT_MAIN);

        self::assertSame('', $this->subject()->buildForPage('site-attached', 'https://site-attached.example.com/about'));
    }

    #[Test]
    public function buildForPageReturnsNothingWhenTheActiveSiteDoesNotExist(): void
    {
        $this->setUpBackendUser(self::EDITOR);

        self::assertSame('', $this->subject()->buildForPage('site-orphan', 'https://site-orphan.example.com/'));
    }

    /** Helpers */

    private function subject(): DashboardLinkBuilderInterface
    {
        return $this->get(DashboardLinkBuilderInterface::class);
    }

    /**
     * @return array<string, mixed> the query parameters of the link without the route token
     */
    private function linkParameters(string $link): array
    {
        parse_str((string)parse_url($link, PHP_URL_QUERY), $parameters);
        unset($parameters['token']);
        return $parameters;
    }

    /**
     * @param array<string, string> $settings
     */
    private function writeSite(string $identifier, int $rootPageId, array $settings): void
    {
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write($identifier, [
            'rootPageId' => $rootPageId,
            'base' => 'https://' . $identifier . '.example.com/',
            'dependencies' => ['t3g/analytics'],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
            ],
        ]);
        $siteWriter->writeSettings($identifier, $settings);
    }
}
