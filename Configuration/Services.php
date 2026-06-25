<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use T3G\Analytics\DependencyInjection\SitePerformanceWidgetV14Pass;
use T3G\Analytics\DependencyInjection\TopPagesWidgetV14Pass;
use T3G\Analytics\DependencyInjection\TrafficGraphWidgetV14Pass;
use T3G\Analytics\DependencyInjection\TrafficSourcesWidgetV14Pass;
use T3G\Analytics\Service\AnalyticsDataClientInterface;
use T3G\Analytics\Service\DemoAnalyticsDataClient;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    $demoDataEnabled = (bool)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['demoData'] ?? false);
    if (Environment::getContext()->isDevelopment() && $demoDataEnabled) {
        // Services.php runs before Services.yaml, so we use a compiler pass to override
        // the alias after all configuration files have been processed.
        $containerBuilder->addCompilerPass(
            new class () implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    $container->setAlias(AnalyticsDataClientInterface::class, DemoAnalyticsDataClient::class)
                        ->setPublic(true);
                }
            },
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            10,
        );
    }

    if (!interface_exists(WidgetRendererInterface::class)) {
        return;
    }

    // Services.php runs before Services.yaml in TYPO3's per-extension load order, so
    // dashboard.widget.analyticsTopPages does not exist in the container yet at this point.
    // We register a compiler pass that runs after all configs are loaded (priority 10,
    // before DashboardWidgetPass at priority 0) to swap the class to the v14 implementation.
    $containerBuilder->addCompilerPass(
        new TopPagesWidgetV14Pass(),
        PassConfig::TYPE_BEFORE_OPTIMIZATION,
        10,
    );

    $containerBuilder->addCompilerPass(
        new SitePerformanceWidgetV14Pass(),
        PassConfig::TYPE_BEFORE_OPTIMIZATION,
        10,
    );

    $containerBuilder->addCompilerPass(
        new TrafficGraphWidgetV14Pass(),
        PassConfig::TYPE_BEFORE_OPTIMIZATION,
        10,
    );

    $containerBuilder->addCompilerPass(
        new TrafficSourcesWidgetV14Pass(),
        PassConfig::TYPE_BEFORE_OPTIMIZATION,
        10,
    );
};
