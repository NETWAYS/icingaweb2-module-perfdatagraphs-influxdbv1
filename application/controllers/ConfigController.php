<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv1\Controllers;

use Icinga\Module\Perfdatagraphsinfluxdbv1\Forms\PerfdataGraphsInfluxDBv1ConfigForm;

use Icinga\Application\Config;
use Icinga\Web\Widget\Tabs;

use ipl\Html\HtmlString;
use ipl\Web\Compat\CompatController;

/**
 * ConfigController manages the configuration for the PerfdataGraphs Influxdbv1 Module.
 */
class ConfigController extends CompatController
{
    protected bool $disableDefaultAutoRefresh = true;

    /**
     * Initialize the Controller.
     */
    public function init(): void
    {
        // Assert the user has access to this controller.
        $this->assertPermission('config/modules');
        parent::init();
    }

    /**
     * generalAction provides the configuration form.
     * For now we have everything on a single Tab, might be extended in the future.
     */
    public function generalAction(): void
    {
        $config = Config::module('perfdatagraphsinfluxdbv1');

        $c = [
            'influx_api_url' => $config->get('influx', 'api_url'),
            'influx_api_timeout' => (int) $config->get('influx', 'api_timeout'),
            'influx_api_database' => $config->get('influx', 'api_database'),
            'influx_api_username' => $config->get('influx', 'api_username'),
            'influx_api_password' => $config->get('influx', 'api_password'),
            'influx_api_tls_insecure' => (bool) $config->get('influx', 'api_tls_insecure'),
        ];

        $form = (new PerfdataGraphsInfluxDBv1ConfigForm())
            ->populate($c)
            ->setIniConfig($config);

        $form->handleRequest();

        $this->addContent(new HtmlString($form->render()));
    }

    /**
     * Merge tabs with other tabs contained in this tab panel.
     *
     * @param Tabs $tabs
     */
    protected function mergeTabs(Tabs $tabs): void
    {
        foreach ($tabs->getTabs() as $tab) {
            $this->tabs->add($tab->getName(), $tab);
        }
    }
}
