<?php

// Stubs for TYPO3 v13 dashboard types. No strict_types so conditional
// interface declarations are valid. Guards prevent redeclaration when
// the real cms-dashboard package is available (local dummy install).

namespace TYPO3\CMS\Dashboard\Widgets;

use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

if (!interface_exists(WidgetInterface::class)) {
    interface WidgetInterface
    {
        public function renderWidgetContent(): string;
        public function getOptions(): array;
    }
}

if (!interface_exists(WidgetConfigurationInterface::class)) {
    interface WidgetConfigurationInterface
    {
        public function getIdentifier(): string;
        public function getServiceName(): string;
        public function getGroupNames(): array;
        public function getTitle(): string;
        public function getDescription(): string;
        public function getIconIdentifier(): string;
        public function getHeight(): string;
        public function getWidth(): string;
    }
}

if (!interface_exists(AdditionalCssInterface::class)) {
    interface AdditionalCssInterface
    {
        public function getCssFiles(): array;
    }
}

if (!interface_exists(JavaScriptInterface::class)) {
    interface JavaScriptInterface
    {
        /** @return list<JavaScriptModuleInstruction> */
        public function getJavaScriptModuleInstructions(): array;
    }
}
