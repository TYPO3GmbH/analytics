<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * The caches of ext_localconf.php hold API data. "typo3 cache:flush" and the cache buttons
 * of the backend flush the group "pages", so the caches must be in this group.
 */
final class CacheConfigurationTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function cacheProvider(): iterable
    {
        yield 'status' => ['tx_analytics_status'];
        yield 'page analytics' => ['tx_analytics_pages'];
        yield 'top pages' => ['tx_analytics_top_pages'];
        yield 'site performance' => ['tx_analytics_site_performance'];
        yield 'traffic graph' => ['tx_analytics_traffic_graph'];
        yield 'traffic sources' => ['tx_analytics_traffic_sources'];
        yield 'plans' => ['tx_analytics_plans'];
    }

    #[Test]
    #[DataProvider('cacheProvider')]
    public function flushingThePageCachesFlushesTheCache(string $cacheIdentifier): void
    {
        $cacheManager = $this->get(CacheManager::class);
        $cache = $cacheManager->getCache($cacheIdentifier);
        $cache->set('entry', ['visits' => 1]);

        $cacheManager->flushCachesInGroup('pages');

        self::assertFalse($cache->has('entry'));
    }
}
