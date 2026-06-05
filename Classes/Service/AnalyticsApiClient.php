<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Exception\AnalyticsApiException;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

final readonly class AnalyticsApiClient
{
    private const INTP_ID = 'cad26303-1c79-415e-8b39-45d8aadfb7f3';

    public function __construct(
        private RequestFactory $requestFactory,
        private ApiConfiguration $apiConfiguration,
        private HmacSigner $hmacSigner,
        private ApiExceptionExtractor $exceptionExtractor,
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
                        'intpId' => self::INTP_ID,
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
        $path = '/api/status/' . $websiteId;
        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getBaseUrl() . '/status/' . $websiteId,
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
    public function fetchDashboardUrl(string $websiteId, string $instanceId, string $instanceSecret): ?string
    {
        $path = '/api/dashboard-url/' . $websiteId;
        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getBaseUrl() . '/dashboard-url/' . $websiteId,
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
     * @throws AnalyticsApiException
     */
    public function fetchCheckoutUrl(string $websiteId, string $instanceId, string $instanceSecret): ?string
    {
        $path = '/api/checkout-url/' . $websiteId;
        try {
            $response = $this->requestFactory->request(
                $this->apiConfiguration->getBaseUrl() . '/checkout-url/' . $websiteId,
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
}
