<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Controller;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use T3G\Analytics\Controller\BackendModuleController;
use T3G\Analytics\Service\CipherService;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Renders the site list of the backend module through the real controller from the DI container.
 *
 * The sites are real site configurations, the backend users come from Fixtures/site_list.csv
 * and the real page access check evaluates their page mounts. Only the HTTP transport to the
 * status API is replaced: each registered site gets the status that matches its website ID.
 *
 *   - site-active:       registered, enough credits
 *   - site-low:          registered, 10 % of the credits left
 *   - site-exhausted:    registered, no credits left
 *   - site-unregistered: not registered
 *
 * Backend users: 1 admin, 2 editor (no Analytics Manager), 3 manager (Analytics Manager via group).
 */
final class BackendModuleSiteListTest extends FunctionalTestCase
{
    private const ADMIN = 1;
    private const EDITOR = 2;
    private const MANAGER = 3;

    private const WEBSITE_ID_ACTIVE = 'f2dddfbb-a7c5-4503-b461-5a6022d91522';
    private const WEBSITE_ID_LOW = 'd693edbc-668f-4cd5-a789-b65c0cb4748e';
    private const WEBSITE_ID_EXHAUSTED = '3be1f3e0-820b-42ef-8666-13c0bbf9874e';
    private const INSTANCE_ID = 'ae518ea5-9ca5-4270-84d3-23fc87c241d3';

    /** Consumption per website ID, as the status API returns it. */
    private const CONSUMPTION = [
        self::WEBSITE_ID_ACTIVE => ['stpLimit' => 1000, 'stpConsumed' => 100],
        self::WEBSITE_ID_LOW => ['stpLimit' => 1000, 'stpConsumed' => 900],
        self::WEBSITE_ID_EXHAUSTED => ['stpLimit' => 1000, 'stpConsumed' => 1000, 'exhausted' => true],
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    /** @var array<int, array<string, mixed>> */
    private array $httpHistory = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpHistory = [];
        $stack = HandlerStack::create(static function (RequestInterface $request): PromiseInterface {
            $websiteId = basename($request->getUri()->getPath());
            if (!isset(self::CONSUMPTION[$websiteId])) {
                return Create::promiseFor(new Response(404, [], '{}'));
            }
            return Create::promiseFor(new Response(200, [], json_encode([
                'status' => 'active',
                'consumption' => self::CONSUMPTION[$websiteId],
            ], JSON_THROW_ON_ERROR)));
        });
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $stack;

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        $this->importCSVDataSet(__DIR__ . '/Fixtures/site_list.csv');
        $this->writeSite('site-active', 1, self::WEBSITE_ID_ACTIVE);
        $this->writeSite('site-low', 2, self::WEBSITE_ID_LOW);
        $this->writeSite('site-exhausted', 3, self::WEBSITE_ID_EXHAUSTED);
        $this->writeSite('site-unregistered', 4, null);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']);
        parent::tearDown();
    }

    /** Sites and groups */

    #[Test]
    public function siteListShowsRegisteredSitesAsActiveAndUnregisteredSitesAsInactive(): void
    {
        $this->setUpBackendUser(self::ADMIN);

        $xpath = $this->renderSiteList();

        self::assertEqualsCanonicalizing(
            ['site-active', 'site-low', 'site-exhausted'],
            $this->siteIdentifiersIn($xpath, 'tx-analytics-site-group-active'),
        );
        self::assertSame(['site-unregistered'], $this->siteIdentifiersIn($xpath, 'tx-analytics-site-group-inactive'));
        self::assertCount(3, $this->httpHistory, 'Only registered sites must request their status');
    }

    #[Test]
    public function siteListShowsTheUpdateDateOfTheStatusInTheLocaleOfTheBackendUser(): void
    {
        $this->setUpBackendUser(self::ADMIN);

        $xpath = $this->renderSiteList();

        $dates = $xpath->query('//tr[@id="tx-analytics-site-details-site-active"]//dd');
        $texts = array_map(static fn (\DOMNode $node): string => trim($node->textContent), iterator_to_array($dates));
        $today = (new \DateTimeImmutable())->format('M j, Y');
        self::assertNotEmpty(
            array_filter($texts, static fn (string $text): bool => str_starts_with($text, $today)),
            'Expected the update date "' . $today . '" in the site details, got: ' . implode(' | ', $texts),
        );
    }

    /** Credit warnings */

    #[Test]
    public function siteListMarksSitesWithLowOrExhaustedCreditsAndOpensTheirDetails(): void
    {
        $this->setUpBackendUser(self::ADMIN);

        $xpath = $this->renderSiteList();

        self::assertSame('table-warning', $this->siteRowClass($xpath, 'site-low'));
        self::assertSame('table-danger', $this->siteRowClass($xpath, 'site-exhausted'));
        self::assertSame('', $this->siteRowClass($xpath, 'site-active'));

        self::assertFalse($this->isDetailsRowHidden($xpath, 'site-low'));
        self::assertFalse($this->isDetailsRowHidden($xpath, 'site-exhausted'));
        self::assertTrue($this->isDetailsRowHidden($xpath, 'site-active'));
    }

    #[Test]
    public function editorWithoutAnalyticsManagerPermissionSeesTheCreditWarnings(): void
    {
        $this->setUpBackendUser(self::EDITOR);

        $xpath = $this->renderSiteList();

        self::assertSame(1, $xpath->query('//tr[@id="tx-analytics-site-details-site-low"]//div[contains(@class, "alert-warning")]')->length);
        self::assertSame(1, $xpath->query('//tr[@id="tx-analytics-site-details-site-exhausted"]//div[contains(@class, "alert-danger")]')->length);
    }

    /** Actions for Analytics Managers */

    #[Test]
    public function editorWithoutAnalyticsManagerPermissionSeesNoManagerActions(): void
    {
        $this->setUpBackendUser(self::EDITOR);

        $xpath = $this->renderSiteList();

        self::assertSame(0, $xpath->query('//form[contains(@class, "tx-analytics-registration-form")]')->length);
        self::assertSame(0, $xpath->query('//form[contains(@class, "tx-analytics-status-form")]')->length);
        self::assertSame(0, $xpath->query('//button[@data-collapse-action="open"]')->length, 'No "Register" button');
        self::assertSame(0, $this->managePlanButtonCount($xpath));
        // The dashboard stays available.
        self::assertSame(3, $xpath->query('//a[contains(@href, "dashboard")]')->length);
    }

    #[Test]
    public function analyticsManagerSeesTheManagerActions(): void
    {
        $this->setUpBackendUser(self::MANAGER);

        $this->assertManagerActions($this->renderSiteList());
    }

    #[Test]
    public function adminSeesTheManagerActions(): void
    {
        $this->setUpBackendUser(self::ADMIN);

        $this->assertManagerActions($this->renderSiteList());
    }

    /** Collapsed state in the user settings */

    #[Test]
    public function siteListCollapsesOnlyTheInactiveGroupByDefault(): void
    {
        $this->setUpBackendUser(self::ADMIN);

        $xpath = $this->renderSiteList();

        self::assertSame('0', $this->plansCollapsedAttribute($xpath));
        self::assertFalse($this->isHidden($xpath, 'tx-analytics-site-group-active'));
        self::assertTrue($this->isHidden($xpath, 'tx-analytics-site-group-inactive'));
    }

    #[Test]
    public function siteListRestoresTheCollapsedStateFromTheUserSettings(): void
    {
        $backendUser = $this->setUpBackendUser(self::ADMIN);
        $backendUser->uc['tx_analytics']['collapsed'] = [
            'plans' => '1',
            'siteGroup' => ['active' => '1', 'inactive' => '0'],
        ];

        $xpath = $this->renderSiteList();

        self::assertSame('1', $this->plansCollapsedAttribute($xpath));
        self::assertTrue($this->isHidden($xpath, 'tx-analytics-site-group-active'));
        self::assertFalse($this->isHidden($xpath, 'tx-analytics-site-group-inactive'));
    }

    /** Helpers */

    private function assertManagerActions(\DOMXPath $xpath): void
    {
        self::assertSame(
            1,
            $xpath->query('//tr[@id="tx-analytics-site-details-site-unregistered"]//form[contains(@class, "tx-analytics-registration-form")]')->length,
        );
        self::assertSame(1, $xpath->query('//button[@data-collapse-action="open"]')->length, 'One "Register" button');
        self::assertSame(3, $xpath->query('//form[contains(@class, "tx-analytics-status-form")]')->length);
        self::assertSame(3, $this->managePlanButtonCount($xpath));
    }

    private function renderSiteList(): \DOMXPath
    {
        $response = $this->get(BackendModuleController::class)->indexAction($this->buildModuleRequest());
        self::assertSame(200, $response->getStatusCode());

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML((string)$response->getBody());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($document);
    }

    /**
     * @return list<string>
     */
    private function siteIdentifiersIn(\DOMXPath $xpath, string $groupContentId): array
    {
        $nodes = $xpath->query('//div[@id="' . $groupContentId . '"]//div[contains(@class, "tx-analytics-site-name")]//code');
        return array_values(array_map(static fn (\DOMNode $node): string => trim($node->textContent), iterator_to_array($nodes)));
    }

    private function siteRowClass(\DOMXPath $xpath, string $siteIdentifier): string
    {
        $row = $xpath->query('//tr[@id="tx-analytics-site-details-' . $siteIdentifier . '"]/preceding-sibling::tr[1]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $row);
        return trim($row->getAttribute('class'));
    }

    private function isDetailsRowHidden(\DOMXPath $xpath, string $siteIdentifier): bool
    {
        return $this->isHidden($xpath, 'tx-analytics-site-details-' . $siteIdentifier);
    }

    private function isHidden(\DOMXPath $xpath, string $id): bool
    {
        $element = $xpath->query('//*[@id="' . $id . '"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $element, 'Element #' . $id . ' not found');
        return $element->hasAttribute('hidden');
    }

    private function plansCollapsedAttribute(\DOMXPath $xpath): string
    {
        $element = $xpath->query('//*[@id="tx-analytics-plans-root"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $element);
        return $element->getAttribute('data-collapsed');
    }

    /**
     * Counts the "Manage plan" and "Subscribe" buttons in the action column. The links in the credit warnings are not counted.
     */
    private function managePlanButtonCount(\DOMXPath $xpath): int
    {
        return $xpath->query('//a[contains(@class, "btn") and contains(@href, "manage")]')->length;
    }

    private function writeSite(string $identifier, int $rootPageId, ?string $websiteId): void
    {
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write($identifier, [
            'rootPageId' => $rootPageId,
            'base' => 'https://' . $identifier . '.example.com/',
            'dependencies' => ['t3g/analytics'],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
            ],
        ]);
        if ($websiteId !== null) {
            $siteWriter->writeSettings($identifier, [
                'websiteId' => $websiteId,
                'instanceId' => self::INSTANCE_ID,
                'instanceSecret' => (new CipherService())->encrypt('test-instance-secret'),
            ]);
        }
    }

    private function buildModuleRequest(): ServerRequest
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

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }
}
