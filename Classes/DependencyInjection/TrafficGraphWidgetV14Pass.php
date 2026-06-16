<?php

declare(strict_types=1);

namespace T3G\Analytics\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use T3G\Analytics\Dashboard\Widget\TrafficGraphWidgetV14;

/**
 * Swaps dashboard.widget.analyticsTrafficGraph to TrafficGraphWidgetV14 on TYPO3 v14+.
 *
 * Must run before DashboardWidgetPass (priority 0) so that getClass() already returns
 * the v14 class when DashboardWidgetPass reads it for AdminOnlyWidgetInterface detection.
 */
final class TrafficGraphWidgetV14Pass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('dashboard.widget.analyticsTrafficGraph')) {
            return;
        }

        $definition = $container->getDefinition('dashboard.widget.analyticsTrafficGraph');
        $definition->setClass(TrafficGraphWidgetV14::class)->setAutowired(true);
    }
}
