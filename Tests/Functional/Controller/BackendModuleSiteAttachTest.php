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
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Tests\Fixtures\AnalyticsIds;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
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
 */
final class BackendModuleSiteAttachTest extends FunctionalTestCase
{
    private const MANAGER = 1;
    private const MANAGER_MAIN_ONLY = 2;
    private const MANAGER_SECOND_ONLY = 3;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpHistory = [];
        $this->whitelistErrorStatus = 0;
        $stack = HandlerStack::create(function (RequestInterface $request): PromiseInterface {
            $path = $request->getUri()->getPath();
            return Create::promiseFor(match (true) {
                $this->whitelistErrorStatus !== 0 && str_contains($path, '/whitelisted-domains') => new Response($this->whitelistErrorStatus, [], '{"detail":"error"}'),
                $request->getMethod() === 'POST' && str_ends_with($path, '/whitelisted-domains') => new Response(200, [], json_encode([
                    'domains' => [json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR)['domain']],
                ], JSON_THROW_ON_ERROR)),
                $request->getMethod() === 'DELETE' => new Response(204),
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
        $this->writeSite('site-attached', 3, [...$this->credentials, 'attachedTo' => 'site-main', 'customSetting' => 'attached']);
        $this->writeSite('site-no-host', 4, [], '/');
        $this->writeSite('site-orphan', 5, [...$this->credentials, 'attachedTo' => 'site-removed']);
    }

    protected function tearDown(): void
    {
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
    public function siteWithoutHostInItsBaseCannotBeAttached(): void
    {
        $this->setUpBackendUser(self::MANAGER);

        $response = $this->attach('site-no-host', 'site-main');

        self::assertSame(500, $response->getStatusCode());
        self::assertSame([], $this->requestsTo('POST', '/whitelisted-domains'));
        self::assertSame([], $this->localSettings('site-no-host'));
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

    /**
     * @param array<string, mixed> $settings
     */
    private function writeSite(string $identifier, int $rootPageId, array $settings, ?string $base = null): void
    {
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write($identifier, [
            'rootPageId' => $rootPageId,
            'base' => $base ?? 'https://' . $identifier . '.example.com/',
            'dependencies' => ['t3g/analytics'],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
            ],
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
