<?php

declare(strict_types=1);

namespace T3G\Analytics\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use T3G\Analytics\Service\SiteDataStorageInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;

final readonly class TrackingCodeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SiteDataStorageInterface $siteDataStorage,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $response;
        }

        $trackingCode = $this->siteDataStorage->get($site, 'trackingCode');
        if ($trackingCode === '' || $this->siteDataStorage->get($site, 'status') !== 'active') {
            return $response;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string)$response->getBody();
        if (!str_contains($body, '</body>')) {
            return $response;
        }

        $newBodyContent = preg_replace('/<\/body>/i', "\n" . $trackingCode . "\n</body>", $body, 1);
        if ($newBodyContent === null || $newBodyContent === $body) {
            return $response;
        }

        $newBody = new Stream('php://temp', 'r+');
        $newBody->write($newBodyContent);

        return $response->withBody($newBody);
    }
}
