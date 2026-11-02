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
 * @copyright Daniel Berthereau, 2018-2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services): void
    {
        $plugins = $services->get('ControllerPluginManager');
        $translate = $plugins->get('translate');
        $messenger = $plugins->get('messenger');

        if (version_compare($oldVersion, '3.4.6', '<')) {
            $messenger->addSuccess(
                $translate('A new button was added in main menu "Vocabularies".') // @translate
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
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Vocabulary',
            'view.browse.actions',
            [$this, 'handleViewBrowseActions']
        );
    }

    /**
     * Add the button Create vocabulary.
     *
     * @todo Add an event in main view to avoid this hack.
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

        $plugins = $view->getHelperPluginManager();

        $userIsAllowed = $plugins->get('userIsAllowed');
        if (!$userIsAllowed(\Omeka\Api\Adapter\VocabularyAdapter::class, 'create')) {
            return;
        }

        $vars = $view->vars();
        $url = $plugins->get('url');
        $translate = $plugins->get('translate');
        $hyperlink = $plugins->get('hyperlink');

        $html = $hyperlink($translate('Create a vocabulary'), $url('admin/custom-ontology'), ['class' => 'button']);
        $content = $vars->offsetGet('content');
        $content = strtr($content, ['<div id="page-actions">' => '<div id="page-actions">' . PHP_EOL . $html]);
        $vars->offsetSet('content', $content);
    }

    /**
     * Add a link to delete the unused terms of the vocabularies of the module.
     */
    public function handleViewBrowseActions(Event $event): void
    {
        /**
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Api\Representation\VocabularyRepresentation $vocabulary
         */
        $view = $event->getTarget();
        $vocabulary = $event->getParam('resource');
        // The event is triggered on the pages of terms too.
        if (!$vocabulary instanceof \Omeka\Api\Representation\VocabularyRepresentation) {
            return;
        }

        $services = $this->getServiceLocator();
        $isManagedVocabulary = $services->get('ControllerPluginManager')->get('isManagedVocabulary');
        if (!$isManagedVocabulary($vocabulary->prefix(), $vocabulary->namespaceUri())) {
            return;
        }

        $plugins = $view->getHelperPluginManager();
        $userIsAllowed = $plugins->get('userIsAllowed');
        if (!$vocabulary->userIsAllowed('update')
            || !$userIsAllowed(\Omeka\Api\Adapter\ResourceClassAdapter::class, 'delete')
            || !$userIsAllowed(\Omeka\Api\Adapter\PropertyAdapter::class, 'delete')
        ) {
            return;
        }

        $url = $plugins->get('url');
        $translate = $plugins->get('translate');
        echo '<li>' . $plugins->get('hyperlink')('', $url('admin/custom-ontology', ['action' => 'vocabulary', 'id' => $vocabulary->id()]), [
            'class' => 'fas fa-eraser',
            'title' => $translate('Delete unused terms'),
        ]) . '</li>';
    }
}
