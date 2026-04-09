<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Functional\Bootstrap;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase as BaseFunctionalTestCase;

abstract class FunctionalTestCase extends BaseFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/analytics',
    ];

    protected array $coreExtensionsToLoad = [
        'core',
        'backend',
        'extbase',
        'fluid',
    ];
}
