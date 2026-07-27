<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Controller\PlansAjaxController;
use T3G\Analytics\Service\PlansServiceInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\Locales;
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

        $this->subject = new PlansAjaxController(
            $this->plansService,
            new ApiConfiguration(),
            new Locales(),
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
    public function handleIncludesContactEmailDefaultInResponse(): void
    {
        $this->plansService->method('getPlans')->willReturn([]);

        $response = $this->subject->handle(new ServerRequest());

        $body = json_decode((string)$response->getBody(), true);
        self::assertSame('support@typo3.com', $body['contactEmail']);
    }

    #[Test]
    public function handleIncludesCustomContactEmailFromConfVars(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['contactEmail'] = 'partner@example.com';
        $subject = new PlansAjaxController($this->plansService, new ApiConfiguration(), new Locales());

        $response = $subject->handle(new ServerRequest());

        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['contactEmail']);
        $body = json_decode((string)$response->getBody(), true);
        self::assertSame('partner@example.com', $body['contactEmail']);
    }

    #[Test]
    public function handleIncludesShowCustomPlanTrueByDefault(): void
    {
        $this->plansService->method('getPlans')->willReturn([]);

        $response = $this->subject->handle(new ServerRequest());

        $body = json_decode((string)$response->getBody(), true);
        self::assertTrue($body['showCustomPlan']);
    }

    #[Test]
    public function handleIncludesShowCustomPlanFalseWhenDisabledViaConfVars(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['showCustomPlan'] = '0';
        $subject = new PlansAjaxController($this->plansService, new ApiConfiguration(), new Locales());

        $response = $subject->handle(new ServerRequest());

        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['showCustomPlan']);
        $body = json_decode((string)$response->getBody(), true);
        self::assertFalse($body['showCustomPlan']);
    }

    #[Test]
    public function handlePassesIntpIdFromConfigurationToService(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['intpId'] = 'configured-intp-id';

        $this->plansService->expects(self::once())
            ->method('getPlans')
            ->with('configured-intp-id')
            ->willReturn([]);

        $this->subject->handle(new ServerRequest());

        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['intpId']);
    }
}
