<?php

declare(strict_types=1);

namespace T3G\Analytics\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use T3G\Analytics\Dashboard\Widget\TrafficSourcesWidgetV14;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * Replaces dashboard.widget.analyticsTrafficSources with four section-locked variants on
 * TYPO3 v14+ (Channel, Devices, Browser, Countries) and removes the generic definition.
 *
 * Must run before DashboardWidgetPass (priority 0) so that getClass() already returns
 * the v14 class when DashboardWidgetPass reads it for AdminOnlyWidgetInterface detection.
 */
final class TrafficSourcesWidgetV14Pass implements CompilerPassInterface
{
    private const LLL = 'LLL:EXT:analytics/Resources/Private/Language/locallang.xlf:';

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

        $sectionVariants = [
            'Channel' => [
                'section' => 'sources',
                'title' => self::LLL . 'dashboardWidget.trafficSourcesChannel.title',
                'description' => self::LLL . 'dashboardWidget.trafficSourcesChannel.description',
                'iconIdentifier' => 'analytics-traffic-sources-channel-widget-icon',
            ],
            'Devices' => [
                'section' => 'devices',
                'chartType' => 'donut',
                'title' => self::LLL . 'dashboardWidget.trafficSourcesDevices.title',
                'description' => self::LLL . 'dashboardWidget.trafficSourcesDevices.description',
                'iconIdentifier' => 'analytics-traffic-sources-devices-widget-icon',
            ],
            'Browser' => [
                'section' => 'browser',
                'chartType' => 'donut',
                'title' => self::LLL . 'dashboardWidget.trafficSourcesBrowser.title',
                'description' => self::LLL . 'dashboardWidget.trafficSourcesBrowser.description',
                'iconIdentifier' => 'analytics-traffic-sources-browser-widget-icon',
            ],
            'Countries' => [
                'section' => 'countries',
                'title' => self::LLL . 'dashboardWidget.trafficSourcesCountries.title',
                'description' => self::LLL . 'dashboardWidget.trafficSourcesCountries.description',
                'iconIdentifier' => 'analytics-traffic-sources-countries-widget-icon',
            ],
        ];

        foreach ($sectionVariants as $suffix => $config) {
            $variantDef = new Definition(TrafficSourcesWidgetV14::class);
            $variantDef->setAutowired(true)
                ->setArgument('$uriBuilder', new Reference(UriBuilder::class))
                ->setArgument('$section', $config['section'])
                ->setArgument('$chartType', $config['chartType'] ?? 'list');

            foreach ($tags as $tag) {
                $tag['identifier'] = 'analyticsTrafficSources' . $suffix;
                $tag['title'] = $config['title'];
                $tag['description'] = $config['description'];
                $tag['iconIdentifier'] = $config['iconIdentifier'];
                $tag['height'] = 'medium';
                $tag['width'] = 'small';
                $variantDef->addTag('dashboard.widget', $tag);
            }

            $container->setDefinition('dashboard.widget.analyticsTrafficSources' . $suffix, $variantDef);
        }

        $container->removeDefinition('dashboard.widget.analyticsTrafficSources');
    }
}
