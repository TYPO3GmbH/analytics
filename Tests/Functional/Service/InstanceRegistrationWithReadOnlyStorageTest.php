<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Service;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use T3G\Analytics\Exception\AnalyticsApiException;
use T3G\Analytics\Service\CipherServiceInterface;
use T3G\Analytics\Service\InstanceRegistrationServiceInterface;
use T3G\Analytics\Service\SiteDataStorageInterface;
use T3G\Analytics\Tests\Functional\Bootstrap\FunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Regression test for https://github.com/TYPO3GmbH/analytics/issues/19. Only the HTTP transport is replaced.
 */
final class InstanceRegistrationWithReadOnlyStorageTest extends FunctionalTestCase
{
    private const SITE = 'main';
    private const DATA_PATH = 'analytics-data';
    private const WEBSITE_ID = 'f2dddfbb-a7c5-4503-b461-5a6022d91522';
    private const INSTANCE_ID = 'ae518ea5-9ca5-4270-84d3-23fc87c241d3';

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        ],
    ];

    private MockHandler $mockHandler;

    /** @var array<int, array{request: RequestInterface}> */
    private array $httpHistory = [];

    /** @var list<string> */
    private array $readOnlyPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpHistory = [];
        $this->mockHandler = new MockHandler();
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->httpHistory));
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $stack;

        $this->get(SiteWriter::class)->write(self::SITE, [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'dependencies' => ['t3g/analytics'],
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->readOnlyPaths as $path) {
            chmod($path, 0775);
        }
        unset(
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'],
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'],
        );
        GeneralUtility::rmdir($this->dataDirectory(), true);
        parent::tearDown();
    }

    #[Test]
    public function withoutConfigurationTheRegistrationSendsNoRequestWhenTheSiteDirectoryIsReadOnly(): void
    {
        $this->makeReadOnly(Environment::getConfigPath() . '/sites/' . self::SITE);

        $this->assertRegistrationFailsWithoutRequest('', 'typo3conf/sites/main/settings.yaml');
    }

    #[Test]
    public function withSeparateFileTheRegistrationSendsNoRequestWhenTheDirectoryCannotBeCreated(): void
    {
        $parent = $this->dataDirectory();
        GeneralUtility::mkdir_deep($parent);
        $this->makeReadOnly($parent);

        $this->assertRegistrationFailsWithoutRequest(self::DATA_PATH . '/sites', $this->dataLocation('sites/main.yaml'));
    }

    #[Test]
    public function withSeparateFileTheRegistrationWritesTheCredentialsIntoTheFile(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = self::DATA_PATH;
        $this->mockHandler->append(new Response(200, [], json_encode([
            'websiteId' => self::WEBSITE_ID,
            'instanceId' => self::INSTANCE_ID,
            'instanceSecret' => 'instance-secret',
        ], JSON_THROW_ON_ERROR)));
        $settingsYaml = Environment::getConfigPath() . '/sites/' . self::SITE . '/settings.yaml';
        $settingsYamlBefore = is_file($settingsYaml) ? file_get_contents($settingsYaml) : null;

        $this->get(InstanceRegistrationServiceInterface::class)->register($this->site(), 'admin@example.com');

        self::assertCount(1, $this->httpHistory);
        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/auth/register/instance', $request->getUri()->getPath());
        $body = json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://example.com', $body['domain']);
        self::assertSame('admin@example.com', $body['email']);

        $persisted = $this->get(SiteDataStorageInterface::class)->loadPersisted($this->site());
        self::assertSame(self::WEBSITE_ID, $persisted['websiteId']);
        self::assertSame(self::INSTANCE_ID, $persisted['instanceId']);
        self::assertNotSame('instance-secret', $persisted['instanceSecret'], 'The secret must be stored encrypted');
        self::assertSame('instance-secret', $this->get(CipherServiceInterface::class)->decrypt($persisted['instanceSecret']));
        self::assertSame(
            $settingsYamlBefore,
            is_file($settingsYaml) ? file_get_contents($settingsYaml) : null,
            'The registration must not change the settings.yaml'
        );
    }

    private function assertRegistrationFailsWithoutRequest(string $siteDataPath, string $expectedLocation): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['siteDataPath'] = $siteDataPath;

        try {
            $this->get(InstanceRegistrationServiceInterface::class)->register($this->site(), 'admin@example.com');
            self::fail('The registration must fail');
        } catch (AnalyticsApiException $e) {
            self::assertStringContainsString($expectedLocation, $e->getMessage());
            self::assertStringContainsString('Check file system permissions', $e->getMessage());
        }
        self::assertCount(0, $this->httpHistory, 'The extension must not send a request to the API');
    }

    private function makeReadOnly(string $directory): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores file permissions');
        }
        chmod($directory, 0555);
        $this->readOnlyPaths[] = $directory;
    }

    private function site(): Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE);
    }

    private function dataDirectory(): string
    {
        return Environment::getProjectPath() . '/' . self::DATA_PATH;
    }

    private function dataLocation(string $file): string
    {
        return substr($this->dataDirectory(), strlen(Environment::getProjectPath()) + 1) . '/' . $file;
    }
}
