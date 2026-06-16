<?php

declare(strict_types=1);

namespace T3G\Analytics\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use T3G\Analytics\Dashboard\Widget\SitePerformanceWidgetV14;

/**
 * Swaps dashboard.widget.analyticsSitePerformance to SitePerformanceWidgetV14 on TYPO3 v14+.
 *
 * Must run before DashboardWidgetPass (priority 0) so that getClass() already returns
 * the v14 class when DashboardWidgetPass reads it for AdminOnlyWidgetInterface detection.
 */
final class SitePerformanceWidgetV14Pass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('dashboard.widget.analyticsSitePerformance')) {
            return;
        }

        $definition = $container->getDefinition('dashboard.widget.analyticsSitePerformance');
        $definition->setClass(SitePerformanceWidgetV14::class)->setAutowired(true);
    }
}
