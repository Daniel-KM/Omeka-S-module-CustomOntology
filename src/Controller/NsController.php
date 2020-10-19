<?php declare(strict_types=1);
namespace CustomOntology\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Representation\VocabularyRepresentation;

class NsController extends AbstractActionController
{
    public function browseAction()
    {
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

        $format = $this->params()->fromQuery('format');
        switch ($format) {
            case 'html':
                break;
            case 'turtle':
            default:
                $ontology = $this->convertVocabularyToOntology($vocabulary);
                $turtle = $this->createTurtle($ontology);
                return $this->responseAsFile($turtle);
        }

        $view = new ViewModel;
        $view->setVariable('ontology', $vocabulary);
        return $view;
    }

    /**
     * Check if a vocabulary is managed as a custom ontology.
     *
     * @param VocabularyRepresentation $ontology
     * @return bool
     */
    protected function isOntologyManaged(VocabularyRepresentation $ontology)
    {
        $urlHelper = $this->viewHelpers()->get('Url');
        $namespaceUri = $ontology->namespaceUri();
        $prefix = $ontology->prefix();
        $ns = $urlHelper('ns/prefix', ['prefix' => $prefix], ['force_canonical' => true]);
        $ns = rtrim($ns, '/#');
        return strpos($namespaceUri, $ns) === 0;
    }

    /**
     * Convert a vocabulary into an array of resource classes and properties.
     *
     * @todo Avoid conversion into array and use easyrdf, or simplify CreateTurtle.
     *
     * @param VocabularyRepresentation $vocabulary
     * @return array
     */
    protected function convertVocabularyToOntology(VocabularyRepresentation $vocabulary)
    {
        $ontology = [];

        $vocabularyEntity = $this->api()
            ->read('vocabularies', $vocabulary->id(), [], ['responseContent' => 'resource'])->getContent();

        $ontology['ontology'] = [
            'o:namespace_uri' => $vocabulary->namespaceUri(),
            'o:prefix' => $vocabulary->prefix(),
            'o:label' => $vocabulary->label(),
            'o:comment' => $vocabulary->comment(),
        ];

        foreach (
            [
                'resource_classes' => 'getResourceClasses',
                'properties' => 'getProperties',
            ] as $type => $method) {
            foreach ($vocabularyEntity->$method() as $element) {
                $ontology[$type][] = [
                    'o:vocabulary' => $vocabularyEntity,
                    'o:local_name' => $element->getLocalName(),
                    'o:label' => $element->getLabel(),
                    'o:comment' => $element->getComment(),
                ];
            }
        }

        return $ontology;
    }
}
