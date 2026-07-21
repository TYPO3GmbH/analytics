<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

interface PlansServiceInterface
{
    /**
     * @return list<array{
     *   name: string,
     *   displayName: string,
     *   touchpoints: int,
     *   touchpointsFormatted: string,
     *   isFree: bool,
     *   isTrial: bool,
     *   currency: string,
     *   monthlyPrice: string|null,
     *   yearlyPrice: string|null,
     *   monthlyEquiv: string|null,
     *   hasOwnDashboards: bool,
     * }>
     */
    public function getPlans(string $intpId): array;
}
