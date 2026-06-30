<?php

declare(strict_types=1);

namespace T3G\Analytics\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

readonly class BackendPageAccessChecker implements BackendPageAccessCheckerInterface
{
    public function userCanAccessPage(int $pageId): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return true;
        }
        return BackendUtility::readPageAccess(
            $pageId,
            $backendUser->getPagePermsClause(Permission::PAGE_SHOW)
        ) !== false;
    }
}
