<?php

namespace Kanboard\Plugin\EmlBlock;

use Kanboard\Core\Plugin\Base;

/**
 * EmlBlock - renders a raw e-mail pasted into an ```eml fenced block as
 * formatted Markdown (header table, own text, quoted history).
 *
 * The text helper is overridden on the app.bootstrap event (onStartup), which
 * fires after EVERY plugin's initialize(). Kanboard loads plugins in unsorted
 * directory order, so this guarantees we register after MarkdownPlus and can
 * inherit from its helper.
 */
class Plugin extends Base
{
    public function initialize()
    {
    }

    public function onStartup()
    {
        try {
            $this->container['helper']->register('text', '\Kanboard\Plugin\EmlBlock\Helper\EmlTextHelper');
        } catch (\Exception $e) {
            $this->logger->error('EmlBlock: failed to override the text helper: '.$e->getMessage());
        }
    }

    public function getPluginName()
    {
        return 'EmlBlock';
    }

    public function getPluginAuthor()
    {
        return 'Zsolt Varga';
    }

    public function getPluginVersion()
    {
        return '0.1.0';
    }

    public function getPluginDescription()
    {
        return 'Render ```eml fenced blocks (raw RFC 822 e-mail) as formatted Markdown';
    }

    public function getPluginHomepage()
    {
        return '';
    }

    public function getCompatibleVersion()
    {
        return '>=1.2.0';
    }
}
