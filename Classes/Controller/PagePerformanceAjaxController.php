<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Service\BackendPageAccessCheckerInterface;
use T3G\Analytics\Service\PagePerformanceBarRenderer;
use T3G\Analytics\Service\PagePerformanceDataProvider;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class PagePerformanceAjaxController
{
    public function __construct(
        private PagePerformanceDataProvider $dataProvider,
        private PagePerformanceBarRenderer $renderer,
        private BackendPageAccessCheckerInterface $pageAccessChecker,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $pageId = (int)($params['pageId'] ?? 0);
        $days = $this->dataProvider->normalizeDays((int)($params['days'] ?? 7));
        $languageId = (int)($params['languageId'] ?? 0);

        if ($pageId <= 0) {
            return new JsonResponse(['error' => 'Missing pageId'], 400);
        }

        if (!$this->pageAccessChecker->userCanAccessPage($pageId)) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $site = $this->dataProvider->trySite($pageId);
        $language = $this->dataProvider->trySiteLanguage($site, $languageId);
        $pageUrl = $site !== null ? $this->dataProvider->resolvePageUrl($site, $pageId, $language) : '';
        $detailsUri = $this->dataProvider->buildDetailsUri($site, $days, $pageUrl);

        $queryParams = $languageId !== 0 ? ['languages' => [$languageId]] : [];
        $metrics = $this->dataProvider->getMetrics($pageId, $site, $language, $days);
        $html = $this->renderer->renderBar($pageId, $language?->getLanguageId() ?? 0, $days, $metrics, $queryParams, $detailsUri);

        return new JsonResponse(['html' => $html]);
    }
}
