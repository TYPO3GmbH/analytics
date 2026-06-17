<?php

declare(strict_types=1);

namespace T3G\Analytics\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use T3G\Analytics\Dashboard\Widget\TrafficSourcesWidgetV14;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * Swaps dashboard.widget.analyticsTrafficSources to TrafficSourcesWidgetV14 on TYPO3 v14+.
 *
 * Must run before DashboardWidgetPass (priority 0) so that getClass() already returns
 * the v14 class when DashboardWidgetPass reads it for AdminOnlyWidgetInterface detection.
 */
final class TrafficSourcesWidgetV14Pass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('dashboard.widget.analyticsTrafficSources')) {
            return;
        }

        $definition = $container->getDefinition('dashboard.widget.analyticsTrafficSources');
        $definition->setClass(TrafficSourcesWidgetV14::class)
            ->setAutowired(true)
            ->setArgument('$uriBuilder', new Reference(UriBuilder::class));

        $tags = $definition->getTag('dashboard.widget');
        $definition->clearTag('dashboard.widget');
        foreach ($tags as $tag) {
            $tag['height'] = 'medium';
            $tag['width'] = 'small';
            $definition->addTag('dashboard.widget', $tag);
        }
    }
}
