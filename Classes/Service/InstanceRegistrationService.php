<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use Psr\Log\LoggerInterface;
use T3G\Analytics\Analytics;
use T3G\Analytics\Utility\ApiExceptionUtility;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Site\SiteSettingsService;

readonly class InstanceRegistrationService
{
    public function __construct(
        private RequestFactory $requestFactory,
        private CipherService $cipherService,
        private SiteSettingsService $siteSettingsService,
        private SiteSettingsFactory $siteSettingsFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Registers a site instance with the Analytics API and persists the
     * returned credentials to site settings.
     *
     * @throws \RuntimeException when the API call fails or the response is incomplete
     */
    public function register(Site $site, string $email): void
    {
        $siteIdentifier = $site->getIdentifier();

        try {
            $response = $this->requestFactory->request(
                Analytics::getApiBaseUrl() . '/auth/register/instance',
                'POST',
                [
                    'json' => [
                        'intpId' => Analytics::INTP_ID,
                        'domain' => rtrim($site->getBase()->__toString(), '/'),
                        'email' => $email,
                    ],
                    'verify' => false,
                ]
            );

            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $reason = ApiExceptionUtility::extractReason($e);
            $this->logger->error('Registration: API request failed.', ['siteIdentifier' => $siteIdentifier, 'reason' => $reason]);
            throw new \RuntimeException($reason, 0, $e);
        }

        $instanceId = (string)($data['instanceId'] ?? '');
        $websiteId = (string)($data['websiteId'] ?? '');
        $instanceSecret = (string)($data['instanceSecret'] ?? '');

        if ($websiteId === '' || $instanceId === '') {
            $this->logger->error('Registration: API response incomplete.', ['siteIdentifier' => $siteIdentifier, 'data' => $data]);
            throw new \RuntimeException('API response is missing websiteId or instanceId.', 2375629894);
        }

        $encryptedSecret = $instanceSecret !== '' ? $this->cipherService->encrypt($instanceSecret) : '';

        $existing = $this->siteSettingsFactory->loadLocalSettings($siteIdentifier) ?? [];
        $this->siteSettingsService->writeSettings($site, array_merge($existing, [
            'websiteId' => $websiteId,
            'instanceId' => $instanceId,
            'instanceSecret' => $encryptedSecret,
        ]));

        $this->logger->info('Site successfully registered.', ['siteIdentifier' => $siteIdentifier, 'websiteId' => $websiteId]);
    }
}
