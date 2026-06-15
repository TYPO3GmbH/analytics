<?php

declare(strict_types=1);

namespace T3G\Analytics\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use T3G\Analytics\Dashboard\Widget\TopPagesWidgetV14;

/**
 * Swaps dashboard.widget.analyticsTopPages to TopPagesWidgetV14 on TYPO3 v14+.
 *
 * Services.php runs before Services.yaml (per-extension load order in TYPO3), so the
 * service definition does not exist yet when Services.php executes. This compiler pass
 * runs after all extension configs are loaded, at which point the definition is available.
 * It must run before DashboardWidgetPass (priority 0) so that getClass() already returns
 * the v14 class when DashboardWidgetPass reads it for AdminOnlyWidgetInterface detection.
 */
final class TopPagesWidgetV14Pass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('dashboard.widget.analyticsTopPages')) {
            return;
        }

        $definition = $container->getDefinition('dashboard.widget.analyticsTopPages');
        $definition->setClass(TopPagesWidgetV14::class)->setAutowired(true);

        // v14 widget is more compact (server-side rendered, no toolbar dropdowns)
        $tags = $definition->getTag('dashboard.widget');
        $definition->clearTag('dashboard.widget');
        foreach ($tags as $tag) {
            $tag['width'] = 'small';
            $definition->addTag('dashboard.widget', $tag);
        }
    }
}
