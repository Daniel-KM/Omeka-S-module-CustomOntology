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

        return new ViewModel([
            'ontologies' => $ontologies,
        ]);
    }

    public function showAction()
    {
        $prefix = $this->params()->fromRoute('prefix');

        // Throw exception automatically.
        $vocabulary = $this->api()->read('vocabularies', [
            'prefix' => $prefix,
        ])->getContent();

        if (!$this->isOntologyManaged($vocabulary)) {
            return $this->notFoundAction();
        }

        // Default is turtle.

        $format = $this->params()->fromQuery('format');

        if ($format !== 'html') {
            $ontology = $this->convertVocabularyToOntology($vocabulary);
            $turtle = $this->createTurtle($ontology);
            return $this->responseAsFile($turtle);
        }

        return new ViewModel([
            'ontology' => $vocabulary,
        ]);
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
