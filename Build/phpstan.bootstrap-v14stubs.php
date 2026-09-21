<?php

// Stubs for TYPO3 v14-only types. No strict_types so conditional
// interface/class declarations are valid. Guards prevent redeclaration when
// the real packages are available (local dummy install or CI with cms-install).

namespace TYPO3\CMS\Dashboard\Widgets;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Settings\SettingsInterface;

if (!interface_exists(WidgetRendererInterface::class)) {
    interface WidgetRendererInterface
    {
        /** @return list<SettingDefinition> */
        public function getSettingsDefinitions(): array;

        public function renderWidget(WidgetContext $context): WidgetResult;
    }
}

if (!class_exists(WidgetContext::class)) {
    final class WidgetContext
    {
        public SettingsInterface $settings;
        public ServerRequestInterface $request;
    }
}

if (!class_exists(WidgetResult::class)) {
    final class WidgetResult
    {
        public function __construct(
            public string $content,
            public bool $refreshable = false,
            public ?string $label = null,
        ) {}
    }
}

namespace TYPO3\CMS\Install\Updates;

if (!interface_exists(UpgradeWizardInterface::class)) {
    interface UpgradeWizardInterface
    {
        public function getTitle(): string;
        public function getDescription(): string;
        public function executeUpdate(): bool;
        public function updateNecessary(): bool;
        /** @return string[] */
        public function getPrerequisites(): array;
    }
}

namespace TYPO3\CMS\Install\Attribute;

if (!class_exists(UpgradeWizard::class)) {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    class UpgradeWizard
    {
        public function __construct(public string $identifier) {}
    }
}
