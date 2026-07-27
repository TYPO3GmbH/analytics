<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Service\PlansServiceInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\Locales;

final readonly class PlansAjaxController
{
    public function __construct(
        private PlansServiceInterface $plansService,
        private ApiConfiguration $apiConfiguration,
        private Locales $locales,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $plans = $this->plansService->getPlans($this->apiConfiguration->getIntpId());
        $locale = $this->locales->createLocaleFromUserPreferences($GLOBALS['BE_USER'] ?? null)->getLanguageCode();
        return new JsonResponse([
            'plans' => $plans,
            'contactEmail' => $this->apiConfiguration->getContactEmail(),
            'showCustomPlan' => $this->apiConfiguration->isCustomPlanEnabled(),
            'locale' => $locale,
        ]);
    }
}
