<?php

declare(strict_types=1);

namespace T3G\Analytics\Backend;

use Doctrine\DBAL\Exception;
use T3G\Analytics\Service\SiteDataProvider;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownItem;
use TYPO3\CMS\Backend\Template\ModuleTemplate;

final readonly class ModuleConfigurator
{
    public function __construct(
        private UriBuilder $uriBuilder,
        private SiteDataProvider $siteDataProvider,
    ) {
    }

    /**
     * @param array<string, mixed> $shortcutArguments
     * @throws Exception|RouteNotFoundException
     */
    public function configureModuleTemplate(
        ModuleTemplate $moduleTemplate,
        string $title,
        string $context = '',
        string $moduleClass = '',
        string $shortcutRouteIdentifier = 'site_analytics',
        array $shortcutArguments = [],
        string $shortcutDisplayName = '',
        string $selectedSiteIdentifier = '',
        string $siteSelectRouteIdentifier = ''
    ): void {
        $moduleTemplate->setTitle($title, $context);
        if ($moduleClass !== '') {
            $moduleTemplate->setModuleClass($moduleClass);
        }

        $docHeader = $moduleTemplate->getDocHeaderComponent();
        $docHeader->enable();
        $displayName = $shortcutDisplayName !== '' ? $shortcutDisplayName : $title;

        if (method_exists($docHeader, 'setShortcutContext')) {
            $docHeader->setShortcutContext($shortcutRouteIdentifier, $displayName, $shortcutArguments);
        } else {
            $buttonBar = $docHeader->getButtonBar();
            $shortcutButton = $buttonBar->makeShortcutButton()
                ->setRouteIdentifier($shortcutRouteIdentifier)
                ->setArguments($shortcutArguments)
                ->setDisplayName($displayName);
            $buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT);
        }

        $this->addRegisteredSitesDropdown($moduleTemplate, $selectedSiteIdentifier, $siteSelectRouteIdentifier);
    }

    public function addBreadcrumbSuffix(
        ModuleTemplate $moduleTemplate,
        string $identifier,
        string $label,
        string $iconIdentifier
    ): void {
        $docHeader = $moduleTemplate->getDocHeaderComponent();
        $methodName = 'addBreadcrumbSuffixNode';
        $breadcrumbNodeClass = 'TYPO3\\CMS\\Backend\\Dto\\Breadcrumb\\BreadcrumbNode';

        if (!method_exists($docHeader, $methodName) || !class_exists($breadcrumbNodeClass)) {
            return;
        }

        $docHeader->{$methodName}(...)(
            new $breadcrumbNodeClass(
                identifier: $identifier,
                label: $label,
                icon: $iconIdentifier,
                iconOverlay: null,
                url: null,
            )
        );
    }

    /**
     * @throws Exception
     * @throws RouteNotFoundException
     */
    private function addRegisteredSitesDropdown(
        ModuleTemplate $moduleTemplate,
        string $selectedSiteIdentifier,
        string $routeIdentifier
    ): void {
        if ($routeIdentifier === '') {
            return;
        }

        $sites = $this->siteDataProvider->registeredSiteOptions();
        if (count($sites) <= 1) {
            return;
        }

        $registeredSitesLabel = $this->translate('label.registeredSites');
        $selectedLabel = $registeredSitesLabel;
        foreach ($sites as $site) {
            if ($site['identifier'] === $selectedSiteIdentifier) {
                $selectedLabel = $site['label'];
                break;
            }
        }

        $dropdown = $moduleTemplate->getDocHeaderComponent()
            ->getButtonBar()
            ->makeDropDownButton()
            ->setLabel($selectedLabel)
            ->setTitle($registeredSitesLabel)
            ->setShowLabelText(true);

        foreach ($sites as $site) {
            $dropdownItem = new DropDownItem();
            $dropdownItem->setLabel($site['label']);
            $dropdownItem->setTitle($site['label']);
            $dropdownItem->setHref((string)$this->uriBuilder->buildUriFromRoute(
                $routeIdentifier,
                ['siteIdentifier' => $site['identifier']]
            ));
            $dropdownItem->setActive($site['identifier'] === $selectedSiteIdentifier);

            $dropdown->addItem($dropdownItem);
        }

        $moduleTemplate->getDocHeaderComponent()
            ->getButtonBar()
            ->addButton($dropdown, ButtonBar::BUTTON_POSITION_LEFT);
    }

    private function translate(string $key): string
    {
        $lll = 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:' . $key;
        $value = (string)($GLOBALS['LANG']->sL($lll) ?? '');
        return $value !== '' ? $value : $key;
    }
}
