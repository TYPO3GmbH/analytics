<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Symfony\Component\Uid\UuidV4;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

readonly class AnalyticsApiClient implements AnalyticsApiClientInterface
{
    public function __construct(
        private RequestFactory $requestFactory,
        private ApiConfiguration $apiConfiguration,
        private HmacSignerInterface $hmacSigner,
        private ApiExceptionExtractorInterface $exceptionExtractor,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws AnalyticsApiException
     */
    public function registerInstance(Site $site, string $email): array
    {
        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getBaseUrl() . '/auth/register/instance',
                'POST',
                array_merge($this->apiConfiguration->getRequestOptions(), $this->apiConfiguration->getAuthOptions(), [
                    'json' => [
                        'intpId' => $this->apiConfiguration->getIntpId(),
                        'domain' => rtrim($site->getBase()->__toString(), '/'),
                        'email' => $email,
                    ],
                ])
            );
            /** @var array<string, mixed> $data */
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return $data;
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
     * @return array<string, mixed>
     * @throws AnalyticsApiException
     */
    public function fetchStatus(string $websiteId, string $instanceId, string $instanceSecret): array
    {
        $this->assertValidIds($websiteId, $instanceId);
        $encodedId = rawurlencode($websiteId);
        $path = '/api/status/' . $encodedId;
        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getBaseUrl() . '/status/' . $encodedId,
                'GET',
                array_merge($this->apiConfiguration->getRequestOptions(), [
                    'headers' => $this->hmacSigner->buildHeaders('GET', $path, $instanceId, $instanceSecret),
                ])
            );
            /** @var array<string, mixed> $data */
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return $data;
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
     * @throws AnalyticsApiException
     */
    public function fetchDashboardUrl(string $websiteId, string $instanceId, string $instanceSecret, bool $watcher = false): ?string
    {
        $this->assertValidIds($websiteId, $instanceId);
        $encodedId = rawurlencode($websiteId);
        $path = '/api/dashboard-url/' . $encodedId . ($watcher ? '?role=watcher' : '');
        $url = $this->apiConfiguration->getBaseUrl() . '/dashboard-url/' . $encodedId . ($watcher ? '?role=watcher' : '');
        try {
            $response = $this->requestFactory->request(
                $url,
                'GET',
                array_merge($this->apiConfiguration->getRequestOptions(), [
                    'headers' => $this->hmacSigner->buildHeaders('GET', $path, $instanceId, $instanceSecret),
                ])
            );
            /** @var array<string, mixed> $data */
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return isset($data['dashboardUrl']) ? (string)$data['dashboardUrl'] : null;
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
     * Creates a TYPO3 Analytics API key via the middleware. The returned 'apiKey' is only
     * available in this response and must be stored immediately.
     *
     * @return array{apiKeyId: string, apiKey: string}
     * @throws AnalyticsApiException
     */
    public function createApiKey(string $websiteId, string $instanceId, string $instanceSecret): array
    {
        $this->assertValidIds($websiteId, $instanceId);
        $encodedId = rawurlencode($websiteId);
        $path = '/api/api-keys/' . $encodedId;
        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getBaseUrl() . '/api-keys/' . $encodedId,
                'POST',
                array_merge($this->apiConfiguration->getRequestOptions(), [
                    'headers' => $this->hmacSigner->buildHeaders('POST', $path, $instanceId, $instanceSecret),
                ])
            );
            /** @var array<string, mixed> $body */
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return [
                'apiKeyId' => (string)($body['apiKeyId'] ?? ''),
                'apiKey' => (string)($body['apiKey'] ?? ''),
            ];
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     * @throws AnalyticsApiException
     */
    public function fetchPlans(string $intpId): array
    {
        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getBaseUrl() . '/public/plans?intpId=' . urlencode($intpId),
                'GET',
                array_merge($this->apiConfiguration->getRequestOptions(), $this->apiConfiguration->getAuthOptions())
            );
            /** @var array<string, mixed> $data */
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $packages = $data['packages'] ?? [];
            return is_array($packages) ? array_values($packages) : [];
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
     * @throws AnalyticsApiException
     */
    public function fetchCheckoutUrl(string $websiteId, string $instanceId, string $instanceSecret): ?string
    {
        $this->assertValidIds($websiteId, $instanceId);
        $encodedId = rawurlencode($websiteId);
        $path = '/api/checkout-url/' . $encodedId;
        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getBaseUrl() . '/checkout-url/' . $encodedId,
                'GET',
                array_merge($this->apiConfiguration->getRequestOptions(), [
                    'headers' => $this->hmacSigner->buildHeaders('GET', $path, $instanceId, $instanceSecret),
                ])
            );
            /** @var array<string, mixed> $data */
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return isset($data['checkoutUrl']) ? (string)$data['checkoutUrl'] : null;
        } catch (AnalyticsApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AnalyticsApiException($this->exceptionExtractor->extractReason($e), null, $e);
        }
    }

    /**
     * The middleware creates both ids as UUIDv4. The websiteId goes into the URL and the signed path,
     * the instanceId into the Authorization header.
     *
     * @throws AnalyticsApiException
     */
    private function assertValidIds(string $websiteId, string $instanceId): void
    {
        if (!UuidV4::isValid($websiteId) || !UuidV4::isValid($instanceId)) {
            throw new AnalyticsApiException('The websiteId or the instanceId is not a valid UUIDv4.');
        }
    }
}
