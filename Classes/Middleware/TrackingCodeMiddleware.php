<?php

declare(strict_types=1);

namespace T3G\Analytics\Middleware;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;

final class TrackingCodeMiddleware implements MiddlewareInterface
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $response;
        }

        $trackingCode = $site->getSettings()->get('trackingCode', '');
        if (empty($trackingCode) || $site->getSettings()->get('status', '') !== 'active') {
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

        $body = str_replace('</body>', "\n" . $trackingCode . "\n</body>", $body);

        $newBody = new Stream('php://temp', 'r+');
        $newBody->write($body);

        return $response->withBody($newBody);
    }
}
