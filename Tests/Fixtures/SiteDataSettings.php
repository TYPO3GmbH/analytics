<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Fixtures;

use T3G\Analytics\Service\SiteDataStorageInterface;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * The tests use the short keys, for example "websiteId".
 */
final class SiteDataSettings
{
    /**
     * Site settings as TYPO3 resolves them: a map with the full identifier as key.
     *
     * @param array<string, mixed> $values
     */
    public static function settings(array $values): Settings
    {
        $map = [];
        foreach ($values as $key => $value) {
            $map[SiteDataStorageInterface::SETTINGS_PATH . '.' . $key] = $value;
        }
        return new Settings($map);
    }

    /**
     * The tree that the extension writes into settings.yaml or the separate file.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $otherSettings
     * @return array<string, mixed>
     */
    public static function tree(array $values, array $otherSettings = []): array
    {
        foreach ($values as $key => $value) {
            $otherSettings = ArrayUtility::setValueByPath($otherSettings, SiteDataStorageInterface::SETTINGS_PATH . '.' . $key, $value, '.');
        }
        return $otherSettings;
    }
}
