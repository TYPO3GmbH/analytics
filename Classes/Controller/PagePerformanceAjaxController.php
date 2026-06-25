<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use T3G\Analytics\Service\PagePerformanceBarBuilder;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class PagePerformanceAjaxController
{
    public function __construct(
        private PagePerformanceBarBuilder $builder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $pageId = (int)($params['pageId'] ?? 0);
        $days = $this->builder->normalizeDays((int)($params['days'] ?? 7));
        $languageId = (int)($params['languageId'] ?? 0);

        if ($pageId <= 0) {
            return new JsonResponse(['error' => 'Missing pageId'], 400);
        }

        $site = $this->builder->trySite($pageId);
        $language = $this->builder->trySiteLanguage($site, $languageId);
        $pageUrl = $site !== null ? $this->builder->resolvePageUrl($site, $pageId, $language) : '';
        $detailsUri = $this->builder->buildDetailsUri($site, $days, $pageUrl);

        $queryParams = $languageId !== 0 ? ['languages' => [$languageId]] : [];
        $html = $this->builder->buildHtml($pageId, $site, $language, $days, $queryParams, $detailsUri);

        return new JsonResponse(['html' => $html]);
    }
}
