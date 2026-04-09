<?php

declare(strict_types=1);

namespace T3G\Analytics\Controller;

use TYPO3\CMS\Core\Imaging\IconSize;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use T3G\Analytics\Service\ExampleService;

final readonly class BackendModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ExampleService $exampleService,
        private UriBuilder $uriBuilder,
        private IconFactory $iconFactory,
        private FlashMessageService $flashMessageService
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $uriBuilder = $this->uriBuilder;
        $refreshUri = (string)$uriBuilder->buildUriFromRoute('web_analytics');

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $iconFactory = $this->iconFactory;

        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setTitle('Aktualisieren')
                ->setHref($refreshUri)
                ->setIcon($iconFactory->getIcon('actions-refresh', IconSize::SMALL))
        );

        $this->addFlashMessage(
            'Das Backend-Modul wurde erfolgreich geladen.',
            'TYPO3 Analytics'
        );

        $moduleTemplate->assignMultiple([
            'headline' => 'TYPO3 Analytics Backend Modul',
            'message' => $this->exampleService->getMessage(),
            'phpVersion' => PHP_VERSION,
            'environment' => $this->exampleService->getEnvironment(),
        ]);

        return $moduleTemplate->renderResponse('Backend/Index');
    }

    private function addFlashMessage(
        string $messageBody,
        string $messageTitle = '',
        ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK,
        bool $storeInSession = true
    ): void {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $messageBody,
            $messageTitle,
            $severity,
            $storeInSession
        );

        $flashMessageService = $this->flashMessageService;
        $queue = $flashMessageService->getMessageQueueByIdentifier();

        $queue->addMessage($flashMessage);
    }
}
