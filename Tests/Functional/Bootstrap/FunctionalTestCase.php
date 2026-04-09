<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Bootstrap;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase as BaseFunctionalTestCase;

abstract class FunctionalTestCase extends BaseFunctionalTestCase
{
    /**
     * Load the extension under test via its absolute path so the testing
     * framework can link it into every temporary TYPO3 test instance.
     *
     * @var non-empty-string[]
     */
    protected array $testExtensionsToLoad = [
        'analytics',
    ];

    protected array $coreExtensionsToLoad = [
        'core',
        'backend',
        'extbase',
        'fluid',
    ];
}
