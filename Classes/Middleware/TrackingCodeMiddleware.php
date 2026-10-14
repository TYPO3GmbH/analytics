<?php

declare(strict_types=1);

namespace T3G\Analytics\Middleware;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use T3G\Analytics\Service\TrackingCodeResolver;
use TYPO3\CMS\Core\Http\Stream;

final readonly class TrackingCodeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TrackingCodeResolver $trackingCodeResolver,
    ) {
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $trackingCode = $this->trackingCodeResolver->resolve($request);
        if ($trackingCode === null) {
            return $response;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string)$response->getBody();
        $closingBodyPosition = stripos($body, '</body>');
        if ($closingBodyPosition === false) {
            return $response;
        }

        // Insert the tracking code literally. The CSP hash covers these exact bytes.
        $newBodyContent = substr_replace($body, "\n" . $trackingCode . "\n", $closingBodyPosition, 0);

        $newBody = new Stream('php://temp', 'r+');
        $newBody->write($newBodyContent);

        return $response->withBody($newBody);
    }
}
