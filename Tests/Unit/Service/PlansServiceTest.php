<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Service\AnalyticsApiClientInterface;
use T3G\Analytics\Service\PlansService;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PlansServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private const INTP_ID = 'test-intp-id';

    private FrontendInterface&MockObject $cache;
    private AnalyticsApiClientInterface&MockObject $apiClient;
    private PlansService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->createMock(FrontendInterface::class);
        $this->apiClient = $this->createMock(AnalyticsApiClientInterface::class);

        $this->subject = new PlansService(
            $this->cache,
            $this->apiClient,
        );
    }

    #[Test]
    public function getPlansReturnsCachedDataOnCacheHit(): void
    {
        $cached = [['name' => 'Basic', 'displayName' => 'Basic']];
        $this->cache->method('has')->willReturn(true);
        $this->cache->method('get')->willReturn($cached);
        $this->apiClient->expects(self::never())->method('fetchPlans');

        $result = $this->subject->getPlans(self::INTP_ID);

        self::assertSame($cached, $result);
    }

    #[Test]
    public function getPlansReturnsEmptyArrayOnApiException(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->apiClient->method('fetchPlans')->willThrowException(
            new AnalyticsApiException('API error', 500)
        );
        $this->cache->expects(self::never())->method('set');

        $result = $this->subject->getPlans(self::INTP_ID);

        self::assertSame([], $result);
    }

    #[Test]
    public function getPlansGroupsMonthlyAndYearlyPackagesByName(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->apiClient->method('fetchPlans')->willReturn([
            ['name' => 'Basic', 'touchpoints' => 25000, 'price' => 12.99, 'currency' => 'EUR', 'period' => 'monthly'],
            ['name' => 'Basic', 'touchpoints' => 25000, 'price' => 124.68, 'currency' => 'EUR', 'period' => 'yearly'],
        ]);

        $result = $this->subject->getPlans(self::INTP_ID);

        self::assertCount(1, $result);
        self::assertSame('Basic', $result[0]['name']);
        self::assertSame(12.99, $result[0]['monthlyPrice']);
        self::assertSame(124.68, $result[0]['yearlyPrice']);
        self::assertNotNull($result[0]['monthlyEquiv']);
    }

    #[Test]
    public function getPlansNormalizesMonthlyTypo(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->apiClient->method('fetchPlans')->willReturn([
            ['name' => 'Basic', 'touchpoints' => 25000, 'price' => 12.99, 'currency' => 'EUR', 'period' => 'monhtly'],
        ]);

        $result = $this->subject->getPlans(self::INTP_ID);

        self::assertCount(1, $result);
        self::assertSame(12.99, $result[0]['monthlyPrice']);
        self::assertNull($result[0]['yearlyPrice']);
    }

    #[Test]
    public function getPlansSkipsPackagesWithZeroTouchpoints(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->apiClient->method('fetchPlans')->willReturn([
            ['name' => 'Free',  'touchpoints' => 0,     'price' => 0.0,   'currency' => 'EUR', 'period' => 'monthly'],
            ['name' => 'Basic', 'touchpoints' => 25000, 'price' => 12.99, 'currency' => 'EUR', 'period' => 'monthly'],
        ]);

        $result = $this->subject->getPlans(self::INTP_ID);

        self::assertCount(1, $result);
        self::assertSame('Basic', $result[0]['name']);
    }

    #[Test]
    public function getPlansMarksFreeTrialAsTrial(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->apiClient->method('fetchPlans')->willReturn([
            ['name' => 'unlimited_free_trial', 'touchpoints' => -1, 'price' => 0.0, 'currency' => 'EUR', 'period' => 'monhtly'],
        ]);

        $result = $this->subject->getPlans(self::INTP_ID);

        self::assertCount(1, $result);
        self::assertTrue($result[0]['isTrial']);
        self::assertSame('Free Trial', $result[0]['displayName']);
    }

    #[Test]
    public function getPlansFormatsUnlimitedTouchpoints(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->apiClient->method('fetchPlans')->willReturn([
            ['name' => 'unlimited_free_trial', 'touchpoints' => -1, 'price' => 0.0, 'currency' => 'EUR', 'period' => 'monthly'],
        ]);

        $result = $this->subject->getPlans(self::INTP_ID);

        self::assertSame('∞', $result[0]['touchpointsFormatted']);
    }

    #[Test]
    public function getPlansGrantsOwnDashboardsOnlyToAdvancedAndPro(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->apiClient->method('fetchPlans')->willReturn([
            ['name' => 'Basic',    'touchpoints' => 25000,  'price' => 12.99, 'currency' => 'EUR', 'period' => 'monthly'],
            ['name' => 'Advanced', 'touchpoints' => 62500,  'price' => 24.99, 'currency' => 'EUR', 'period' => 'monthly'],
            ['name' => 'Pro',      'touchpoints' => 125000, 'price' => 39.99, 'currency' => 'EUR', 'period' => 'monthly'],
        ]);

        $result = $this->subject->getPlans(self::INTP_ID);
        $byName = array_column($result, null, 'name');

        self::assertFalse($byName['Basic']['hasOwnDashboards']);
        self::assertTrue($byName['Advanced']['hasOwnDashboards']);
        self::assertTrue($byName['Pro']['hasOwnDashboards']);
    }

    #[Test]
    public function getPlansReturnsPlansSortedByTouchpointsAscendingWithUnlimitedLast(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->apiClient->method('fetchPlans')->willReturn([
            ['name' => 'plan-125k',     'touchpoints' => 125000, 'price' => 39.99, 'currency' => 'EUR', 'period' => 'monthly'],
            ['name' => 'plan-25k',      'touchpoints' => 25000,  'price' => 12.99, 'currency' => 'EUR', 'period' => 'monthly'],
            ['name' => 'plan-unlimited','touchpoints' => -1,     'price' => 0.0,   'currency' => 'EUR', 'period' => 'monthly'],
            ['name' => 'plan-free',     'touchpoints' => 0,      'price' => 0.0,   'currency' => 'EUR', 'period' => 'monthly'],
            ['name' => 'plan-62k',      'touchpoints' => 62500,  'price' => 24.99, 'currency' => 'EUR', 'period' => 'monthly'],
        ]);

        $result = $this->subject->getPlans(self::INTP_ID);
        $touchpoints = array_column($result, 'touchpoints');

        self::assertSame([25000, 62500, 125000, -1], $touchpoints);
    }

    #[Test]
    public function getPlansStoresResultInCache(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->apiClient->method('fetchPlans')->willReturn([
            ['name' => 'Free', 'touchpoints' => 0, 'price' => 0.0, 'currency' => 'EUR', 'period' => 'monthly'],
        ]);
        $this->cache->expects(self::once())->method('set')->with(
            self::stringStartsWith('plans_'),
            self::isArray(),
        );

        $this->subject->getPlans(self::INTP_ID);
    }

    #[Test]
    public function getPlansSkipsPackagesWithEmptyName(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->apiClient->method('fetchPlans')->willReturn([
            ['name' => '',      'touchpoints' => 25000, 'price' => 0.0,   'currency' => 'EUR', 'period' => 'monthly'],
            ['name' => 'Basic', 'touchpoints' => 25000, 'price' => 12.99, 'currency' => 'EUR', 'period' => 'monthly'],
        ]);

        $result = $this->subject->getPlans(self::INTP_ID);

        self::assertCount(1, $result);
        self::assertSame('Basic', $result[0]['name']);
    }

    #[Test]
    public function getPlansComputesCorrectMonthlyEquiv(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->apiClient->method('fetchPlans')->willReturn([
            ['name' => 'Advanced', 'touchpoints' => 62500, 'price' => 239.88, 'currency' => 'EUR', 'period' => 'yearly'],
        ]);

        $result = $this->subject->getPlans(self::INTP_ID);

        // 239.88 / 12 = 19.99
        self::assertSame(19.99, $result[0]['monthlyEquiv']);
    }
}
