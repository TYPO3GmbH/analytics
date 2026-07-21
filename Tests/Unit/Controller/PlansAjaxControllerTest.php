<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Controller\PlansAjaxController;
use T3G\Analytics\Service\PlansServiceInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PlansAjaxControllerTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private PlansServiceInterface&MockObject $plansService;
    private PlansAjaxController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plansService = $this->createMock(PlansServiceInterface::class);

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn('');

        $this->subject = new PlansAjaxController(
            $this->plansService,
            new ApiConfiguration($extensionConfiguration),
        );
    }

    #[Test]
    public function handleReturnsJsonResponseWithPlans(): void
    {
        $plans = [
            ['name' => 'Free', 'displayName' => 'Free', 'isFree' => true],
            ['name' => 'Basic', 'displayName' => 'Basic', 'isFree' => false],
        ];
        $this->plansService->method('getPlans')->willReturn($plans);

        $response = $this->subject->handle(new ServerRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertSame($plans, $body['plans']);
    }

    #[Test]
    public function handleReturnsEmptyPlansArrayWhenServiceReturnsNothing(): void
    {
        $this->plansService->method('getPlans')->willReturn([]);

        $response = $this->subject->handle(new ServerRequest());

        $body = json_decode((string)$response->getBody(), true);
        self::assertSame([], $body['plans']);
    }

    #[Test]
    public function handlePassesIntpIdFromConfigurationToService(): void
    {
        $configWithCustomIntpId = $this->createMock(ExtensionConfiguration::class);
        $configWithCustomIntpId->method('get')
            ->with('analytics', 'intpId')
            ->willReturn('configured-intp-id');

        $controllerWithCustomIntpId = new PlansAjaxController(
            $this->plansService,
            new ApiConfiguration($configWithCustomIntpId),
        );

        $this->plansService->expects(self::once())
            ->method('getPlans')
            ->with('configured-intp-id')
            ->willReturn([]);

        $controllerWithCustomIntpId->handle(new ServerRequest());
    }
}
