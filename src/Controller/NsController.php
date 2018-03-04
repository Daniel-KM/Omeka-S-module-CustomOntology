<?php
namespace CustomOntology\Controller;

use Omeka\Api\Representation\VocabularyRepresentation;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class NsController extends AbstractActionController
{
    public function browseAction()
    {
        $urlHelper = $this->viewHelpers()->get('Url');
        $ontologies = [];

        $vocabularies = $this->api()->search('vocabularies')->getContent();
        foreach ($vocabularies as $vocabulary) {
            if ($this->isOntologyManaged($vocabulary)) {
                $ontologies[] = $vocabulary;
            }
        }

        $view = new ViewModel;
        $view->setVariable('ontologies', $ontologies);
        return $view;
    }

    public function showAction()
    {
        $view = new ViewModel;
        $prefix = $this->params()->fromRoute('prefix');

        $vocabulary = $this->api()->searchOne('vocabularies', [
            'prefix' => $prefix,
        ])->getContent();

        if (empty($vocabulary)) {
            return $this->notFoundAction();
        }

        if (!$this->isOntologyManaged($vocabulary)) {
            return $this->notFoundAction();
        }

        $view = new ViewModel;
        $view->setVariable('ontology', $vocabulary);
        return $view;
    }

    /**
     * Check if a vocabulary is managed as a custom ontology.
     *
     * @param VocabularyRepresentation $ontology
     * @return boolean
     */
    protected function isOntologyManaged(VocabularyRepresentation $ontology)
    {
        $urlHelper = $this->viewHelpers()->get('Url');
        $namespaceUri = $ontology->namespaceUri();
        $prefix = $ontology->prefix();
        $ns = $urlHelper('ns/prefix', ['prefix' => $prefix], ['force_canonical' => true]);
        return strpos($namespaceUri, $ns) === 0;
    }
}
