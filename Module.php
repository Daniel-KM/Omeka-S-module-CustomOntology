<?php declare(strict_types=1);

namespace CustomOntology;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
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

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services)
    {
        $plugins = $services->get('ControllerPluginManager');
        $translate = $plugins->get('translate');
        $messenger = $plugins->get('messenger');

        if (version_compare($oldVersion, '3.4.6', '<')) {
            $messenger->addSuccess(
                $translate('A new button was added in main menu "Vocabularies".') // @translate );
            );
        }
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

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Vocabulary',
            'view.layout',
            [$this, 'handleViewLayout']
        );
    }

    /**
     * Add the button Create vocabulary.
     */
    public function handleViewLayout(Event $event): void
    {
        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();

        $params = $view->params()->fromRoute();
        $action = $params['action'] ?? null;
        if ($action !== 'browse') {
            return;
        }

        $vars = $view->vars();

        $html = $view->hyperlink($view->translate('Create a vocabulary'), $view->url('admin/custom-ontology'), ['class' => 'button']);
        $content = $vars->offsetGet('content');
        $content = str_replace('<div id="page-actions">', '<div id="page-actions">' . PHP_EOL . $html, $content);
        $vars->offsetSet('content', $content);
    }
}
