<?php declare(strict_types=1);

namespace CustomOntology;

use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;

/**
 * Custom Ontology
 *
 * Create specific classes and properties to describe resources when no standard
 * ontologies can be used.
 *
 * @copyright Daniel Berthereau, 2018-2024
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /**
         * @var \Omeka\Permissions\Acl $acl
         * @see \Omeka\Service\AclFactory
         */
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        $acl->allow(null, Controller\NsController::class);
    }
}
