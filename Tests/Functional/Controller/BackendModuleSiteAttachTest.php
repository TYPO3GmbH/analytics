<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Controller;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use T3G\Analytics\Controller\BackendModuleController;
use T3G\Analytics\Service\AnalyticsStatusServiceInterface;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Tests\Fixtures\AnalyticsIds;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;

/**
 * Attaches and detaches sites through the real controller from the DI container.
 *
 * The sites are real site configurations, the backend users come from Fixtures/site_attach.csv
 * and the real page access check evaluates their page mounts. Only the HTTP transport to the
 * API is replaced.
 *
 *   - site-main:     registered and active (root page 1)
 *   - site-second:   not registered (root page 2)
 *   - site-attached: attached to site-main (root page 3)
 *   - site-no-host:  not registered, base "/" (root page 4)
 *   - site-orphan:   attached to a site that does not exist (root page 5)
 *   - site-shared-a: not registered, base "https://example.org/a/" (root page 6)
 *   - site-shared-b: not registered, base "https://example.org/b/" (root page 7)
 *   - site-path:     not registered, base "https://path.example.net/shop/" (root page 8)
 *   - site-shared-root: not registered, base "https://example.org/" (root page 9)
 *   - site-languages: not registered, German language on "https://site-languages.example.de/" (root page 10)
 *   - site-language-shared: not registered, German language on the host of site-main (root page 11)
 */
final class BackendModuleSiteAttachTest extends FunctionalTestCase
{
    private const MANAGER = 1;
    private const MANAGER_MAIN_ONLY = 2;
    private const MANAGER_SECOND_ONLY = 3;
    private const MANAGER_SHARED_ROOT_ONLY = 4;

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    /** @var array<int, array<string, mixed>> */
    private array $httpHistory = [];

    /** @var array<string, mixed> */
    private array $credentials = [];

    /** HTTP status of the whitelist API, 0 for success. */
    private int $whitelistErrorStatus = 0;

    /** The whitelist API rejects only this host. */
    private string $rejectedHost = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpHistory = [];
        $this->whitelistErrorStatus = 0;
        $this->rejectedHost = '';
        $stack = HandlerStack::create(function (RequestInterface $request): PromiseInterface {
            $path = $request->getUri()->getPath();
            return Create::promiseFor(match (true) {
                $this->whitelistErrorStatus !== 0 && str_contains($path, '/whitelisted-domains') => new Response($this->whitelistErrorStatus, [], '{"detail":"error"}'),
                $this->rejectedHost !== '' && $request->getMethod() === 'POST' && str_contains((string)$request->getBody(), '"' . $this->rejectedHost . '"') => new Response(400, [], '{"detail":"error"}'),
                $request->getMethod() === 'POST' && str_ends_with($path, '/whitelisted-domains') => new Response(200, [], json_encode([
                    'domains' => [json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR)['domain']],
                ], JSON_THROW_ON_ERROR)),
                $request->getMethod() === 'DELETE' => new Response(204),
                $request->getMethod() === 'POST' && str_contains($path, '/api-keys/') => new Response(200, [], '{"apiKeyId":"new-api-key-id","apiKey":"new-api-key"}'),
                str_ends_with($path, '/' . AnalyticsIds::WEBSITE_ID) => new Response(200, [], '{"status":"active","consumption":{"stpLimit":1000,"stpConsumed":100}}'),
                default => new Response(404, [], '{}'),
            });
        });
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $stack;

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        $cipherService = new CipherService();
        $this->credentials = [
            'websiteId' => AnalyticsIds::WEBSITE_ID,
            'instanceId' => AnalyticsIds::INSTANCE_ID,
            'instanceSecret' => $cipherService->encrypt('test-instance-secret'),
            'externalWebsiteId' => AnalyticsIds::EXTERNAL_WEBSITE_ID,
            'apiKeyId' => AnalyticsIds::API_KEY_ID,
            'apiKey' => $cipherService->encrypt('test-api-key'),
        ];
        $this->importCSVDataSet(__DIR__ . '/Fixtures/site_attach.csv');
        $this->writeSite('site-main', 1, [...$this->credentials, 'customSetting' => 'main']);
        $this->writeSite('site-second', 2, ['customSetting' => 'second']);
        $this->writeSite('site-attached', 3, [...$this->credentials, 'attachedTo' => 'site-main', 'attachedHosts' => ['site-attached.example.com'], 'customSetting' => 'attached']);
        $this->writeSite('site-no-host', 4, [], '/');
        $this->writeSite('site-orphan', 5, [...$this->credentials, 'attachedTo' => 'site-removed']);
        $this->writeSite('site-shared-a', 6, [], 'https://example.org/a/');
        $this->writeSite('site-shared-b', 7, [], 'https://example.org/b/');
        $this->writeSite('site-path', 8, [], 'https://path.example.net/shop/');
        $this->writeSite('site-shared-root', 9, [], 'https://example.org/');
        $this->writeSite('site-languages', 10, [], null, 'https://site-languages.example.de/');
        $this->writeSite('site-language-shared', 11, [], null, 'https://site-main.example.com/de/');
    }

    protected function tearDown(): void
    {
        foreach (glob(Environment::getConfigPath() . '/sites/*/settings.yaml') ?: [] as $file) {
            chmod($file, 0644);
        }
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
        parent::tearDown();
    }

    /** Attach */

    #[Test]
    public function managerAttachesASiteToAnActiveSite(): void
    {
        $this->setUpBackendUser(self::MANAGER);

        $response = $this->attach('site-second', 'site-main');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $whitelistRequests = $this->requestsTo('POST', '/whitelisted-domains');
        self::assertCount(1, $whitelistRequests);
        self::assertStringContainsString('/website/' . AnalyticsIds::WEBSITE_ID . '/', $whitelistRequests[0]->getUri()->getPath());
        // The API whitelists host names, not URLs.
        self::assertSame(['domain' => 'site-second.example.com'], json_decode((string)$whitelistRequests[0]->getBody(), true));
        // The site gets the credentials of the active site and keeps its own settings.
        $settings = $this->localSettings('site-second');
        self::assertSame('site-main', $settings['attachedTo'] ?? null);
        self::assertSame('second', $settings['customSetting'] ?? null);
        foreach ($this->credentials as $key => $value) {
            self::assertSame($value, $settings[$key] ?? null, $key);
        }
    }

    #[Test]
    public function attachChangesNoSettingsWhenTheApiRejectsTheDomain(): void
    {
        $this->setUpBackendUser(self::MANAGER);
        $this->whitelistErrorStatus = 400;

        $response = $this->attach('site-second', 'site-main');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['customSetting' => 'second'], $this->localSettings('site-second'));
    }

    #[Test]
    public function attachChangesNoSettingsWhenTheActiveSiteHasLostItsInstanceSecret(): void
    {
        $this->setUpBackendUser(self::MANAGER);
        // The status of the active site stays in the cache for 24 hours, also without credentials.
        self::assertNotNull($this->get(AnalyticsStatusServiceInterface::class)->getStatus($this->get(SiteFinder::class)->getSiteByIdentifier('site-main')));
        $settings = [...$this->credentials, 'customSetting' => 'main'];
        unset($settings['instanceSecret']);
        $this->get(SiteWriter::class)->writeSettings('site-main', $settings);

        $response = $this->attach('site-second', 'site-main');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame([], $this->requestsTo('POST', '/whitelisted-domains'));
        self::assertSame(['customSetting' => 'second'], $this->localSettings('site-second'));
    }

    #[Test]
    public function attachAddsTheHostsOfTheLanguagesToTheWhitelist(): void
    {
        $this->setUpBackendUser(self::MANAGER);

        $response = $this->attach('site-languages', 'site-main');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $domains = array_map(static fn (RequestInterface $request): string => json_decode((string)$request->getBody(), true)['domain'], $this->requestsTo('POST', '/whitelisted-domains'));
        self::assertSame(['site-languages.example.com', 'site-languages.example.de'], $domains);
        self::assertSame(['site-languages.example.com', 'site-languages.example.de'], $this->localSettings('site-languages')['attachedHosts'] ?? null);
    }

    #[Test]
    public function attachRemovesTheAddedHostsWhenTheApiRejectsALaterHost(): void
    {
        $this->setUpBackendUser(self::MANAGER);
        $this->rejectedHost = 'site-languages.example.de';

        $response = $this->attach('site-languages', 'site-main');

        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $this->requestsTo('DELETE', '/whitelisted-domains/site-languages.example.com'));
        self::assertCount(0, $this->requestsTo('DELETE', '/whitelisted-domains/site-languages.example.de'));
        self::assertSame([], $this->localSettings('site-languages'));
    }

    #[Test]
    public function attachRemovesTheHostFromTheWhitelistWhenTheSettingsCannotBeWritten(): void
    {
        $this->setUpBackendUser(self::MANAGER);
        $this->makeSettingsReadOnly('site-second');

        $response = $this->withoutWriteWarnings(fn (): ResponseInterface => $this->attach('site-second', 'site-main'));

        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $this->requestsTo('POST', '/whitelisted-domains'));
        self::assertCount(1, $this->requestsTo('DELETE', '/whitelisted-domains/site-second.example.com'));
        self::assertSame(['customSetting' => 'second'], $this->localSettings('site-second'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidAttachRequests(): iterable
    {
        yield 'missing site' => ['', 'site-main'];
        yield 'missing active site' => ['site-second', ''];
        yield 'site is the active site' => ['site-main', 'site-main'];
        yield 'site is registered' => ['site-attached', 'site-main'];
        yield 'active site is not registered' => ['site-second', 'site-no-host'];
        yield 'active site is itself attached' => ['site-second', 'site-attached'];
    }

    #[Test]
    #[DataProvider('invalidAttachRequests')]
    public function attachRejectsInvalidRequests(string $siteIdentifier, string $targetSiteIdentifier): void
    {
        $this->setUpBackendUser(self::MANAGER);

        $response = $this->attach($siteIdentifier, $targetSiteIdentifier);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse(json_decode((string)$response->getBody(), true)['success']);
        self::assertSame([], $this->requestsTo('POST', '/whitelisted-domains'));
        self::assertArrayNotHasKey('attachedTo', $this->localSettings('site-second'));
    }

    #[Test]
    public function attachReturnsNotFoundForAnUnknownSite(): void
    {
        $this->setUpBackendUser(self::MANAGER);

        $response = $this->attach('unknown', 'site-main');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->httpHistory);
    }

    #[Test]
    public function managerWithoutAccessToTheSiteCannotAttachIt(): void
    {
        $this->setUpBackendUser(self::MANAGER_MAIN_ONLY);

        $response = $this->attach('site-second', 'site-main');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->requestsTo('POST', '/whitelisted-domains'));
        self::assertSame(['customSetting' => 'second'], $this->localSettings('site-second'));
    }

    #[Test]
    public function managerWithoutAccessToTheActiveSiteCannotAttachToIt(): void
    {
        $this->setUpBackendUser(self::MANAGER_SECOND_ONLY);

        $response = $this->attach('site-second', 'site-main');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->httpHistory);
        self::assertSame(['customSetting' => 'second'], $this->localSettings('site-second'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function sitesWithoutOwnHost(): iterable
    {
        yield 'no host' => ['site-no-host', self::MANAGER];
        yield 'path, own host' => ['site-path', self::MANAGER];
        yield 'path, shared host' => ['site-shared-a', self::MANAGER];
        yield 'no path, shared host' => ['site-shared-root', self::MANAGER];
        // The check includes the sites that the user cannot access.
        yield 'no path, shared host, no access to the other sites' => ['site-shared-root', self::MANAGER_SHARED_ROOT_ONLY];
        yield 'language on the host of another site' => ['site-language-shared', self::MANAGER];
    }

    #[Test]
    #[DataProvider('sitesWithoutOwnHost')]
    public function siteWithoutOwnHostCannotBeAttached(string $siteIdentifier, int $backendUserId): void
    {
        $this->setUpBackendUser($backendUserId);

        $response = $this->attach($siteIdentifier, 'site-main');

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('must have its own domain without a path', json_decode((string)$response->getBody(), true)['message']);
        self::assertSame([], $this->requestsTo('POST', '/whitelisted-domains'));
        self::assertSame([], $this->localSettings($siteIdentifier));
    }

    /** Detach */

    #[Test]
    public function managerDetachesAnAttachedSite(): void
    {
        $this->setUpBackendUser(self::MANAGER);

        $response = $this->detach('site-attached');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $deleteRequests = $this->requestsTo('DELETE', '/whitelisted-domains/site-attached.example.com');
        self::assertCount(1, $deleteRequests);
        self::assertStringContainsString('/website/' . AnalyticsIds::WEBSITE_ID . '/', $deleteRequests[0]->getUri()->getPath());
        // Detach removes the copied credentials and keeps the own settings of the site.
        self::assertSame(['customSetting' => 'attached'], $this->localSettings('site-attached'));
    }

    #[Test]
    public function detachKeepsTheSettingsWhenTheApiFails(): void
    {
        $this->setUpBackendUser(self::MANAGER);
        $this->whitelistErrorStatus = 400;

        $response = $this->detach('site-attached');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('site-main', $this->localSettings('site-attached')['attachedTo'] ?? null);
    }

    #[Test]
    public function detachRemovesTheStoredHostsFromTheWhitelist(): void
    {
        // The hosts from the time of the attach count, not the current languages of the site.
        $this->get(SiteWriter::class)->writeSettings('site-attached', [
            ...$this->localSettings('site-attached'),
            'attachedHosts' => ['site-attached.example.com', 'site-attached.example.de'],
        ]);
        $this->setUpBackendUser(self::MANAGER);

        $response = $this->detach('site-attached');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertCount(1, $this->requestsTo('DELETE', '/whitelisted-domains/site-attached.example.com'));
        self::assertCount(1, $this->requestsTo('DELETE', '/whitelisted-domains/site-attached.example.de'));
        self::assertSame(['customSetting' => 'attached'], $this->localSettings('site-attached'));
    }

    #[Test]
    public function detachWithoutStoredHostsRemovesOnlyTheSettings(): void
    {
        $settings = $this->localSettings('site-attached');
        unset($settings['attachedHosts']);
        $this->get(SiteWriter::class)->writeSettings('site-attached', $settings);
        $this->setUpBackendUser(self::MANAGER);

        $response = $this->detach('site-attached');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([], $this->httpHistory);
        self::assertSame(['customSetting' => 'attached'], $this->localSettings('site-attached'));
    }

    #[Test]
    public function detachAddsTheHostToTheWhitelistAgainWhenTheSettingsCannotBeWritten(): void
    {
        $this->setUpBackendUser(self::MANAGER);
        $this->makeSettingsReadOnly('site-attached');

        $response = $this->withoutWriteWarnings(fn (): ResponseInterface => $this->detach('site-attached'));

        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $this->requestsTo('DELETE', '/whitelisted-domains/site-attached.example.com'));
        $whitelistRequests = $this->requestsTo('POST', '/whitelisted-domains');
        self::assertCount(1, $whitelistRequests);
        self::assertSame(['domain' => 'site-attached.example.com'], json_decode((string)$whitelistRequests[0]->getBody(), true));
        self::assertSame('site-main', $this->localSettings('site-attached')['attachedTo'] ?? null);
    }

    #[Test]
    public function managerWithAccessToTheAttachedSiteOnlyCanDetachIt(): void
    {
        // Detach removes only the domain of the attached site, so access to the active site is not necessary.
        $this->setUpBackendUser(self::MANAGER_SECOND_ONLY);

        $response = $this->detach('site-attached');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertCount(1, $this->requestsTo('DELETE', '/whitelisted-domains/site-attached.example.com'));
    }

    #[Test]
    public function managerWithoutAccessToTheAttachedSiteCannotDetachIt(): void
    {
        $this->setUpBackendUser(self::MANAGER_MAIN_ONLY);

        $response = $this->detach('site-attached');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->httpHistory);
        self::assertSame('site-main', $this->localSettings('site-attached')['attachedTo'] ?? null);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidDetachRequests(): iterable
    {
        yield 'missing site' => ['', 400];
        yield 'unknown site' => ['unknown', 404];
        yield 'site is not attached' => ['site-main', 500];
    }

    #[Test]
    #[DataProvider('invalidDetachRequests')]
    public function detachRejectsInvalidRequests(string $siteIdentifier, int $expectedStatusCode): void
    {
        $this->setUpBackendUser(self::MANAGER);

        $response = $this->detach($siteIdentifier);

        self::assertSame($expectedStatusCode, $response->getStatusCode());
        self::assertFalse(json_decode((string)$response->getBody(), true)['success']);
        self::assertSame([], $this->httpHistory);
        self::assertSame([...$this->credentials, 'customSetting' => 'main'], $this->localSettings('site-main'));
    }

    /** API key */

    #[Test]
    public function statusRefreshOfAnAttachedSiteCreatesNoApiKey(): void
    {
        $this->setUpBackendUser(self::MANAGER);
        $this->removeApiKey('site-main');
        $this->removeApiKey('site-attached');

        $response = $this->refreshStatus('site-attached');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([], $this->requestsTo('POST', '/api-keys/' . AnalyticsIds::WEBSITE_ID));
        self::assertArrayNotHasKey('apiKeyId', $this->localSettings('site-attached'));
    }

    #[Test]
    public function statusRefreshOfAnAttachedSiteCopiesTheApiKeyOfTheActiveSite(): void
    {
        $this->setUpBackendUser(self::MANAGER);
        $this->removeApiKey('site-attached');

        $response = $this->refreshStatus('site-attached');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([], $this->requestsTo('POST', '/api-keys/' . AnalyticsIds::WEBSITE_ID));
        $settings = $this->localSettings('site-attached');
        self::assertSame($this->credentials['apiKeyId'], $settings['apiKeyId'] ?? null);
        self::assertSame($this->credentials['apiKey'], $settings['apiKey'] ?? null);
    }

    #[Test]
    public function attachedSiteGetsTheApiKeyOnItsNextStatusRefreshWhenTheFirstWriteFailed(): void
    {
        $this->setUpBackendUser(self::MANAGER);
        $this->removeApiKey('site-main');
        $this->removeApiKey('site-attached');
        $this->makeSettingsReadOnly('site-attached');

        $response = $this->withoutWriteWarnings(fn (): ResponseInterface => $this->refreshStatus('site-main'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('new-api-key-id', $this->localSettings('site-main')['apiKeyId'] ?? null);
        self::assertArrayNotHasKey('apiKeyId', $this->localSettings('site-attached'));

        chmod(Environment::getConfigPath() . '/sites/site-attached/settings.yaml', 0644);
        $response = $this->refreshStatus('site-attached');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertCount(1, $this->requestsTo('POST', '/api-keys/' . AnalyticsIds::WEBSITE_ID));
        self::assertSame('new-api-key-id', $this->localSettings('site-attached')['apiKeyId'] ?? null);
        self::assertSame($this->localSettings('site-main')['apiKey'] ?? null, $this->localSettings('site-attached')['apiKey'] ?? null);
    }

    #[Test]
    public function newApiKeyOfAnActiveSiteGoesToItsAttachedSites(): void
    {
        $this->setUpBackendUser(self::MANAGER);
        $this->removeApiKey('site-main');
        $this->removeApiKey('site-attached');

        $response = $this->refreshStatus('site-main');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertCount(1, $this->requestsTo('POST', '/api-keys/' . AnalyticsIds::WEBSITE_ID));
        $mainSettings = $this->localSettings('site-main');
        $attachedSettings = $this->localSettings('site-attached');
        self::assertSame('new-api-key-id', $mainSettings['apiKeyId'] ?? null);
        self::assertSame('new-api-key-id', $attachedSettings['apiKeyId'] ?? null);
        self::assertSame($mainSettings['apiKey'] ?? null, $attachedSettings['apiKey'] ?? null);
        self::assertSame('new-api-key', (new CipherService())->decrypt((string)($attachedSettings['apiKey'] ?? '')));
        // Sites that are not attached to the active site keep their settings.
        self::assertSame(['customSetting' => 'second'], $this->localSettings('site-second'));
    }

    /** Site list */

    #[Test]
    public function siteListShowsAnAttachedSiteBelowItsActiveSite(): void
    {
        $this->setUpBackendUser(self::MANAGER);

        $xpath = $this->renderSiteList();

        $attachedRows = $xpath->query('//tbody[.//tr[not(contains(@class, "tx-analytics-site-attached"))]//code[text()="site-main"]]/tr[contains(@class, "tx-analytics-site-attached")]//code');
        self::assertSame(['site-attached'], $this->textsOf($attachedRows));
    }

    #[Test]
    public function siteListShowsAnAttachedSiteInItsOwnRowWhenItsActiveSiteIsMissing(): void
    {
        $this->setUpBackendUser(self::MANAGER);

        $xpath = $this->renderSiteList();

        self::assertSame(1, $xpath->query('//tr[not(contains(@class, "tx-analytics-site-attached"))]//div[contains(@class, "tx-analytics-site-name")]//code[text()="site-orphan"]')->length);
        self::assertSame(0, $xpath->query('//tr[contains(@class, "tx-analytics-site-attached")]//code[text()="site-orphan"]')->length);
    }

    #[Test]
    public function siteListOffersOnlyActiveSitesWithOwnAnalyticsAsAttachTarget(): void
    {
        $this->setUpBackendUser(self::MANAGER);

        $xpath = $this->renderSiteList();

        $options = $xpath->query('//select[@id="tx-analytics-attach-target-site-second"]/option[@value!=""]/@value');
        self::assertSame(['site-main'], $this->textsOf($options));
    }

    #[Test]
    #[DataProvider('sitesWithoutOwnHost')]
    public function siteListOffersNoAttachForASiteWithoutOwnHost(string $siteIdentifier, int $backendUserId): void
    {
        $this->setUpBackendUser($backendUserId);

        $xpath = $this->renderSiteList();

        $form = '//form[.//input[@name="siteIdentifier"][@value="' . $siteIdentifier . '"]]';
        self::assertSame(0, $xpath->query($form . '//input[@name="mode"]')->length);
        self::assertSame(0, $xpath->query($form . '//select[@name="targetSiteIdentifier"]')->length);
        self::assertSame(1, $xpath->query($form . '//*[contains(@class, "tx-analytics-site-not-attachable")]')->length);
        // The e-mail field shows directly, so the site can be registered as a new site.
        self::assertSame(0, $xpath->query($form . '//fieldset[@data-registration-mode="register"][@hidden]')->length);
        self::assertSame(1, $xpath->query($form . '//input[@name="email"]')->length);
    }

    #[Test]
    public function siteListOffersAttachForASiteWithAnOwnHost(): void
    {
        $this->setUpBackendUser(self::MANAGER);

        $xpath = $this->renderSiteList();

        $form = '//form[.//input[@name="siteIdentifier"][@value="site-second"]]';
        self::assertSame(2, $xpath->query($form . '//input[@name="mode"]')->length);
        self::assertSame(0, $xpath->query($form . '//*[contains(@class, "tx-analytics-site-not-attachable")]')->length);
    }

    /** Dashboard */

    #[Test]
    public function dashboardOfAnAttachedSiteRedirectsToTheSiteList(): void
    {
        $this->setUpBackendUser(self::MANAGER);
        $request = (new ServerRequest(new Uri('https://example.com/typo3/module/site/analytics/dashboard'), 'GET'))
            ->withQueryParams(['siteIdentifier' => 'site-attached'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $response = $this->get(BackendModuleController::class)->dashboardAction($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame([], $this->httpHistory, 'The dashboard URL of the active site must not be requested');
        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages();
        self::assertCount(1, $messages);
        self::assertStringContainsString('is attached to site "site-main"', $messages[0]->getMessage());
    }

    /** Helpers */

    private function renderSiteList(): \DOMXPath
    {
        $path = '/module/site/analytics';
        $request = (new ServerRequest(
            new Uri('https://example.com' . $path),
            'GET',
            'php://input',
            [],
            ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'REQUEST_URI' => $path, 'SCRIPT_NAME' => '/index.php'],
        ))
            ->withAttribute('route', new Route($path, ['packageName' => 't3g/analytics']))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $response = $this->get(BackendModuleController::class)->indexAction($request);
        self::assertSame(200, $response->getStatusCode());

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML((string)$response->getBody());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($document);
    }

    /**
     * @param \DOMNodeList<\DOMNode> $nodes
     * @return list<string>
     */
    private function textsOf(\DOMNodeList $nodes): array
    {
        return array_values(array_map(static fn (\DOMNode $node): string => trim($node->textContent), iterator_to_array($nodes)));
    }

    private function attach(string $siteIdentifier, string $targetSiteIdentifier): ResponseInterface
    {
        return $this->get(BackendModuleController::class)->registerAction($this->buildPostRequest([
            'mode' => 'attach',
            'siteIdentifier' => $siteIdentifier,
            'targetSiteIdentifier' => $targetSiteIdentifier,
        ]));
    }

    private function detach(string $siteIdentifier): ResponseInterface
    {
        return $this->get(BackendModuleController::class)->detachAction($this->buildPostRequest(['siteIdentifier' => $siteIdentifier]));
    }

    /**
     * @return list<RequestInterface>
     */
    private function requestsTo(string $method, string $pathSuffix): array
    {
        $requests = array_map(static fn (array $transaction): RequestInterface => $transaction['request'], $this->httpHistory);
        return array_values(array_filter(
            $requests,
            static fn (RequestInterface $request): bool => $request->getMethod() === $method && str_ends_with($request->getUri()->getPath(), $pathSuffix),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function localSettings(string $siteIdentifier): array
    {
        return $this->get(SiteSettingsFactory::class)->loadLocalSettings($siteIdentifier) ?? [];
    }

    private function refreshStatus(string $siteIdentifier): ResponseInterface
    {
        return $this->get(BackendModuleController::class)->statusAction($this->buildPostRequest(['siteIdentifier' => $siteIdentifier]));
    }

    private function removeApiKey(string $siteIdentifier): void
    {
        $settings = $this->localSettings($siteIdentifier);
        unset($settings['apiKeyId'], $settings['apiKey']);
        $this->get(SiteWriter::class)->writeSettings($siteIdentifier, $settings);
    }

    private function makeSettingsReadOnly(string $siteIdentifier): void
    {
        chmod(Environment::getConfigPath() . '/sites/' . $siteIdentifier . '/settings.yaml', 0444);
    }

    /**
     * The core writes the file with fopen(), which gives a PHP warning for a read-only file.
     *
     * @param callable(): ResponseInterface $action
     */
    private function withoutWriteWarnings(callable $action): ResponseInterface
    {
        set_error_handler(static fn (int $errno, string $message): bool => str_contains($message, 'Permission denied'), E_WARNING);
        try {
            return $action();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function writeSite(string $identifier, int $rootPageId, array $settings, ?string $base = null, ?string $germanBase = null): void
    {
        $languages = [
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
        ];
        if ($germanBase !== null) {
            $languages[] = ['languageId' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF-8', 'base' => $germanBase, 'enabled' => true];
        }
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write($identifier, [
            'rootPageId' => $rootPageId,
            'base' => $base ?? 'https://' . $identifier . '.example.com/',
            'dependencies' => ['t3g/analytics'],
            'languages' => $languages,
        ]);
        if ($settings !== []) {
            $siteWriter->writeSettings($identifier, $settings);
        }
    }

    /**
     * @param array<string, string> $body
     */
    private function buildPostRequest(array $body): ServerRequest
    {
        return (new ServerRequest(new Uri('https://example.com/typo3/module/site/analytics'), 'POST'))
            ->withParsedBody($body)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }
}
