<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Service\PlansServiceInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class PlansAjaxController
{
    public function __construct(
        private PlansServiceInterface $plansService,
        private ApiConfiguration $apiConfiguration,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $plans = $this->plansService->getPlans($this->apiConfiguration->getIntpId());
        return new JsonResponse(['plans' => $plans]);
    }
}
