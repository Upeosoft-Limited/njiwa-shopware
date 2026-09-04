<?php declare(strict_types=1);

/**
 * The plugin itself.
 *
 * Shopware finds everything else from here: src/Resources/config/services.xml
 * is loaded automatically, src/Resources/config/config.xml becomes the
 * settings form, and src/Migration is where the schema lives.
 *
 * There is deliberately no install() work. A plugin that writes rows the
 * moment it is installed is a plugin that has to undo them correctly, and
 * everything this one needs either has a default in the code or is a table the
 * migration creates.
 */

namespace Upeo\Njiwa;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class UpeoNjiwa extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // "Keep user data" is ticked by a merchant who is reinstalling or
        // upgrading, and the record of what was sent to whom is exactly the
        // thing they would be sorry to lose. Only a deliberate clean uninstall
        // drops it.
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        if ($connection instanceof Connection) {
            $connection->executeStatement('DROP TABLE IF EXISTS `upeo_njiwa_message`');
        }

        // The API key and the wording live in system_config under this
        // plugin's own prefix. Shopware removes those itself on a clean
        // uninstall, so there is nothing to do here.
    }
}
