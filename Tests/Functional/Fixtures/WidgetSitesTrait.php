<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Fixtures;

use TYPO3\CMS\Core\Configuration\SiteWriter;

/**
 * Real sites for the widget tests. The backend users in widget_access.csv have
 * both root pages as DB mounts, so the dashboard links of these sites show.
 */
trait WidgetSitesTrait
{
    private function setUpWidgetSites(): void
    {
        $this->importCSVDataSet(__DIR__ . '/widget_sites.csv');
        $siteWriter = $this->get(SiteWriter::class);
        foreach (['my-site' => 10, 'fallback-site' => 11] as $identifier => $rootPageId) {
            $siteWriter->write($identifier, [
                'rootPageId' => $rootPageId,
                'base' => 'https://' . $identifier . '.example.com/',
                'dependencies' => ['t3g/analytics'],
                'languages' => [
                    ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
                ],
            ]);
        }
    }
}
