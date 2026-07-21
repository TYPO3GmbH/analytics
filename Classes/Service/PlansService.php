<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

readonly class PlansService implements PlansServiceInterface
{
    private const PLANS_WITH_OWN_DASHBOARDS = ['Advanced', 'Pro'];

    public function __construct(
        private FrontendInterface $cache,
        private AnalyticsApiClientInterface $apiClient,
    ) {
    }

    public function getPlans(string $intpId): array
    {
        $cacheKey = 'plans_' . sha1($intpId);

        if ($this->cache->has($cacheKey)) {
            /** @var list<array{name: string, displayName: string, touchpoints: int, touchpointsFormatted: string, isFree: bool, isTrial: bool, currency: string, monthlyPrice: string|null, yearlyPrice: string|null, monthlyEquiv: string|null, hasOwnDashboards: bool}> $cached */
            $cached = $this->cache->get($cacheKey);
            return $cached;
        }

        try {
            $packages = $this->apiClient->fetchPlans($intpId);
        } catch (\Throwable) {
            return [];
        }

        $plans = $this->buildPlans($packages);
        $this->cache->set($cacheKey, $plans);

        return $plans;
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @return list<array{name: string, displayName: string, touchpoints: int, touchpointsFormatted: string, isFree: bool, isTrial: bool, currency: string, monthlyPrice: string|null, yearlyPrice: string|null, monthlyEquiv: string|null, hasOwnDashboards: bool}>
     */
    private function buildPlans(array $packages): array
    {
        $grouped = [];

        foreach ($packages as $package) {
            $name = (string)($package['name'] ?? '');
            if ($name === '') {
                continue;
            }

            // Normalize API typo "monhtly" → "monthly"
            $period = strtolower((string)($package['period'] ?? ''));
            if (str_starts_with($period, 'mon')) {
                $period = 'monthly';
            }

            $price = (float)($package['price'] ?? 0);
            $touchpoints = (int)($package['touchpoints'] ?? 0);
            $currency = (string)($package['currency'] ?? 'EUR');

            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'touchpoints' => $touchpoints,
                    'currency' => $currency,
                    'monthlyPrice' => null,
                    'yearlyPrice' => null,
                ];
            }

            if ($period === 'monthly') {
                $grouped[$name]['monthlyPrice'] = $price;
            } elseif ($period === 'yearly') {
                $grouped[$name]['yearlyPrice'] = $price;
            }
        }

        $plans = [];

        foreach ($grouped as $name => $data) {
            $plans[] = $this->preparePlan($name, $data);
        }

        usort($plans, static function (array $a, array $b): int {
            $ta = $a['isTrial'] ? PHP_INT_MIN : ($a['touchpoints'] < 0 ? PHP_INT_MAX : $a['touchpoints']);
            $tb = $b['isTrial'] ? PHP_INT_MIN : ($b['touchpoints'] < 0 ? PHP_INT_MAX : $b['touchpoints']);
            return $ta <=> $tb;
        });

        return $plans;
    }

    /**
     * @param array{touchpoints: int, currency: string, monthlyPrice: float|null, yearlyPrice: float|null} $data
     * @return array{name: string, displayName: string, touchpoints: int, touchpointsFormatted: string, isFree: bool, isTrial: bool, currency: string, monthlyPrice: string|null, yearlyPrice: string|null, monthlyEquiv: string|null, hasOwnDashboards: bool}
     */
    private function preparePlan(string $name, array $data): array
    {
        $monthlyPrice = $data['monthlyPrice'];
        $yearlyPrice = $data['yearlyPrice'];

        return [
            'name' => $name,
            'displayName' => $name === 'unlimited_free_trial' ? 'Free Trial' : $name,
            'touchpoints' => $data['touchpoints'],
            'touchpointsFormatted' => $this->formatTouchpoints($data['touchpoints']),
            'isFree' => $monthlyPrice === null || $monthlyPrice <= 0.0,
            'isTrial' => $name === 'unlimited_free_trial',
            'currency' => $data['currency'],
            'monthlyPrice' => $monthlyPrice !== null ? $this->formatPrice($monthlyPrice) : null,
            'yearlyPrice' => $yearlyPrice !== null ? $this->formatPrice($yearlyPrice) : null,
            'monthlyEquiv' => $yearlyPrice !== null ? $this->formatPrice($yearlyPrice / 12) : null,
            'hasOwnDashboards' => in_array($name, self::PLANS_WITH_OWN_DASHBOARDS, true),
        ];
    }

    private function formatPrice(float $price): string
    {
        return number_format($price, 2, ',', '.');
    }

    private function formatTouchpoints(int $touchpoints): string
    {
        if ($touchpoints < 0) {
            return '∞';
        }
        return number_format($touchpoints, 0, ',', '.');
    }
}
