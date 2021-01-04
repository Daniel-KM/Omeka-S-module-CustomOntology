<?php declare(strict_types=1);
namespace CustomOntology\Controller\Admin;

use CustomOntology\Form\CustomOntologyForm;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Entity\Property;
use Omeka\Entity\ResourceClass;
use Omeka\Entity\Vocabulary;
use Omeka\Stdlib\Message;

class CustomOntologyController extends AbstractActionController
{
    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @see \Omeka\Api\Adapter\VocabularyAdapter
     * @var array Reserved vocabulary prefixes
     */
    protected $reservedPrefixes = [
        // Omeka and module prefixes
        '^o$', '^o-',
        // Prefixes introduced in core code
        '^time$', '^cnt$',
    ];

    /**
     * @var array
     */
    protected $defaultVocabularyPrefixes = [
        'dcterms', 'dctype',
    ];

    /**
     * The posted ontology prefix.
     *
     * @var string
     */
    protected $ontologyPrefix = '';

    /**
     * The created ontology.
     *
     * @var Vocabulary
     */
    protected $ontology;

    /**
     * @param EntityManager $entityManager
     */
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function indexAction()
    {
        $view = new ViewModel;
        $request = $this->getRequest();

        $url = $this->viewHelpers()->get('url');
        $defaultNs = $url('ns/prefix', ['prefix' => 'myprefix'], ['force_canonical' => true]) . '/';

        /** @var \CustomOntology\Form\CustomOntologyForm $form */
        $form = $this->getForm(CustomOntologyForm::class);
        $form->setOptions(['default_ns' => $defaultNs]);
        $form->init();
        $view->setVariable('form', $form);

        if (!$request->isPost()) {
            return $view;
        }

        $params = $this->getRequest()->getPost();
        $form->setData($params);
        if (!$form->isValid()) {
            $this->messenger()->addErrors($form->getMessages());
            return $view;
        }

        $params = $form->getData();
        $action = $this->params()->fromPost('submit', 'submit');

        // TODO Move validation inside form.
        $valid = [];
        $valid['ontology'] = $this->validateOntology(
            $params['ontology_fieldset'],
            $action !== 'download'
        );
        if ($valid['ontology'] && $valid['ontology']['o:prefix']) {
            $this->ontologyPrefix = $valid['ontology']['o:prefix'];
        }

        $valid['resource_classes'] = $this->validateResourceClasses(
            $params['resource_classes_fieldset']['resource_classes'],
            $action === 'download'
        );
        $valid['properties'] = $this->validateProperties(
            $params['properties_fieldset']['properties'],
            $action === 'download'
        );

        if (is_null($valid['ontology'])
            || is_null($valid['resource_classes'])
            || is_null($valid['properties'])
        ) {
            $this->messenger()->addWarning('An error was found. Turtle cannot be created. Nothing was imported.'); // @translate
            return $view;
        }

        if (!array_filter(array_map('array_filter', $valid))) {
            $this->messenger()->addWarning('Nothing to process.'); // @translate
            return $view;
        }

        if ($action === 'download') {
            $turtle = $this->createTurtle($valid);
            if (empty($turtle)) {
                $this->messenger()->addError(sprintf('Unable to create the ontology.')); // @translate
                return $view;
            }

            $filename = (empty($valid['ontology']['o:prefix']) ? 'ontology' : $valid['ontology']['o:prefix']) . '.ttl';
            return $this->responseAsFile($turtle, $filename, 'text/turtle');
        }

        $result = $this->saveOntology($valid['ontology']);
        if ($result === false) {
            return $view;
        }
        if ($result === true) {
            $vocabulary = $this->api()->read('vocabularies',
                ['prefix' => $valid['ontology']['o:prefix']],
                [],
                ['responseContent' => 'resource']
            )->getContent();
            $this->ontology = $vocabulary;

            /** @var \Omeka\Api\Representation\VocabularyRepresentation $vocabulary */
            $vocabulary = $this->api()->read('vocabularies', $vocabulary->getId())->getContent();
            $urlHelper = $this->viewHelpers()->get('Url');
            $message = new Message(
                'The vocabulary "%1$s" (%2$s) has been created (%3$sapi%4$s).', // @translate
                '<a href="' . $urlHelper('admin/default', ['controller' => 'vocabulary']) . '">' . $vocabulary->label() . '</a>',
                $vocabulary->prefix(),
                '<a href="' . $urlHelper('api/default', ['resource' => 'vocabularies', 'id' => $vocabulary->id()]) . '" targer="_blank">', '</a>'
            );
            $message->setEscapeHtml(false);
            $this->messenger()->addSuccess($message);
        }

        $resultResourceClasses = $this->saveResourceClasses($valid['resource_classes']);
        $resultProperties = $this->saveProperties($valid['properties']);

        $this->entityManager->flush();

        if ($resultResourceClasses) {
            $createds = array_map(function ($v) {
                return $v['term'];
            }, $valid['resource_classes']);
            $this->messenger()->addSuccess(new Message(
                '%d resource classes have been created: %s.', // @translate
                count($valid['resource_classes']), implode(', ', $createds)
            ));
        }

        if ($resultProperties) {
            $createds = array_map(function ($v) {
                return $v['term'];
            }, $valid['properties']);
            $this->messenger()->addSuccess(new Message(
                '%d properties have been created: %s.', // @translate
                count($valid['properties']), implode(', ', $createds)
            ));
        }

        $form->init();
        return $view;
    }

    /**
     * Check if the ontology is valid.
     *
     * @param array $ontology
     * @param bool $checkIfExists
     * @return array|null Null if error.
     */
    protected function validateOntology(array $ontology, $checkIfExists = true)
    {
        if (empty($ontology['o:prefix']) && empty($ontology['o:namespace_uri'])) {
            return $ontology;
        }

        if (empty($ontology['o:prefix']) && !empty($ontology['o:namespace_uri'])) {
            $this->messenger()->addError('A namespace uri is set for the ontology, but there is no prefix.'); // @translate
            return;
        }

        if (!empty($ontology['o:prefix']) && empty($ontology['o:namespace_uri'])) {
            $this->messenger()->addError('A prefix is set for the ontology, but there is no namespace uri.'); // @translate
            return;
        }

        $ontology['o:prefix'] = (string) $ontology['o:prefix'];
        $ontology['o:label'] = (string) $ontology['o:label'];
        if (strtolower($ontology['o:prefix']) !== $ontology['o:prefix']) {
            $this->messenger()->addError('The prefix of the ontology must be lowercase.'); // @translate
            return;
        }

        if (!preg_match('~^[a-z][a-z0-9]*$~', $ontology['o:prefix'])) {
            $this->messenger()->addError('The prefix must start with an alphabetic character and must contain only alphanumeric characters.'); // @translate
            return;
        }

        if (!mb_strlen($ontology['o:label'])) {
            $this->messenger()->addError('A label is required for the ontology.'); // @translate
            return;
        }

        $ontology['o:class'] = [];
        $ontology['o:property'] = [];

        if (!$checkIfExists) {
            return $ontology;
        }

        $api = $this->api();
        $vocabulary = $api->searchOne('vocabularies', ['prefix' => $ontology['o:prefix']])->getContent();
        if (!empty($vocabulary)) {
            $this->messenger()->addWarning(new Message(
                'An ontology exists for the prefix "%s".', // @translate
                $ontology['o:prefix']
            ));
            return;
        }

        $vocabulary = $api->searchOne('vocabularies', ['namespace_uri' => $ontology['o:namespace_uri']])->getContent();
        if (!empty($vocabulary)) {
            $this->messenger()->addWarning(new Message(
                'An ontology exists for the namespace uri "%s".', // @translate
                $ontology['o:namespace_uri']
            ));
            return;
        }

        return $ontology;
    }

    /**
     * Check if the resource classes are valid.
     *
     * @param string $resourceClasses
     * @param bool $keepExisting
     * @return array|null Null if error.
     */
    protected function validateResourceClasses($resourceClasses, $keepExisting = true)
    {
        $resourceClasses = $this->validateElements($resourceClasses, 'resource_classes', $keepExisting);
        return $resourceClasses;
    }

    /**
     * Check if the properties are valid.
     *
     * @param string $properties
     * @param bool $keepExisting
     * @return array|null Null if error.
     */
    protected function validateProperties($properties, $keepExisting = true)
    {
        $properties = $this->validateElements($properties, 'properties', $keepExisting);
        return $properties;
    }

    /**
     * Check if the elements are valid.
     *
     * @param string $elements
     * @param string $type "resouce_classes" or "properties"
     * @param bool $keepExisting Keep all good elements in the returned array,
     * even if the elements that exist in Omeka.
     * @return array|null Null if error.
     */
    protected function validateElements($elements, $type, $keepExisting = true)
    {
        $types = [
            'resource_classes' => 'Resource classes', // @translate
            'properties' => 'Properties', // @translate
        ];
        if (!isset($types[$type])) {
            return;
        }

        $result = [];
        $api = $this->api();
        $hasError = false;
        $addedTerms = [];
        $addedLabels = [];

        // The str_replace() allows to fix Apple copy/paste.
        $elements = array_filter(array_map('trim', explode("\n", str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], $elements))));

        foreach ($elements as $key => $elementString) {
            $row = $key + 1;
            $element = [];
            $element['term'] = strtok((string) $elementString, ',') ?: '';
            $element['o:label'] = strtok(',') ?: '';
            $element['o:comment'] = strtok('') ?: '';
            $element = array_map('trim', $element);
            if (!array_filter($element)) {
                continue;
            }

            $textMessage = sprintf('%s, row #%d', $types[$type], $row); // @translate

            $term = $element['term'];
            unset($element['term']);
            if (empty($term)) {
                $hasError = true;
                $this->messenger()->addError(new Message(
                    '%s: A content is defined, but no term.', // @translate
                    $textMessage
                ));
                continue;
            }

            if (empty(strpos($term, ':'))) {
                $hasError = true;
                $this->messenger()->addError(new Message(
                    $type === 'resource_classes'
                        ? '%s: The term must be formatted "prefix:LocalName".' // @translate
                        : '%s: The term must be formatted "prefix:localName".', // @translate
                    $textMessage
                ));
                continue;
            }

            $termArray = array_filter(array_map('trim', explode(':', $term)));
            if (count($termArray) != 2) {
                $hasError = true;
                $this->messenger()->addError(new Message(
                    $type === 'resource_classes'
                        ? '%s: The term must be formatted "prefix:LocalName".' // @translate
                        : '%s: The term must be formatted "prefix:localName".', // @translate
                    $textMessage
                ));
                continue;
            }

            $prefix = $termArray[0];
            $localName = $termArray[1];
            $firstCharacter = substr($localName, 0, 1);
            switch ($type) {
                case 'resource_classes':
                    if (strtoupper($firstCharacter) !== $firstCharacter) {
                        $hasError = true;
                        $this->messenger()->addError(new Message(
                            '%s: The local name part of the term "%s" must start with a upper-case letter.', // @translate
                            $textMessage, $term
                        ));
                        continue 2;
                    }
                    break;
                case 'properties':
                    if (strtolower($firstCharacter) !== $firstCharacter) {
                        $hasError = true;
                        $this->messenger()->addError(new Message(
                            '%s: The local name part of the term "%s" must start with a lower-case letter.', // @translate
                            $textMessage, $term
                        ));
                        continue 2;
                    }
                    break;
            }

            $term = $prefix . ':' . $localName;

            $matches = [];
            $regex = $type === 'resource_classes'
                ? '~^([a-z][a-z0-9]*):([A-Z][a-zA-Z0-9_-]*)$~'
                : '~^([a-z][a-z0-9]*):([a-z][a-zA-Z0-9_-]*)$~';
            preg_match($regex, $term, $matches);
            if (empty($matches[0])) {
                $hasError = true;
                $this->messenger()->addError(new Message(
                    '%s: The term "%s" must start with an alphabetic character and contain only alphanumeric characters, "-" and "_".', // @translate
                    $textMessage, $term
                ));
                continue;
            }

            $prefix = $matches[1];
            $element['o:local_name'] = $matches[2];
            $vocabulary = $api->searchOne('vocabularies',
                ['prefix' => $prefix],
                ['responseContent' => 'resource']
            )->getContent();
            if (empty($vocabulary) && $prefix !== $this->ontologyPrefix) {
                $hasError = true;
                $this->messenger()->addError(new Message(
                    '%s: No ontology exists for prefix "%s".', // @translate
                    $textMessage, $prefix
                ));
                continue;
            }

            if ($vocabulary) {
                if (in_array($prefix, $this->defaultVocabularyPrefixes)) {
                    $hasError = true;
                    $this->messenger()->addError(new Message(
                        '%s: For standardization purpose, this tool forbids to add a term to the default Dublin Core vocabularies "%s".', // @translate
                        $textMessage, $prefix
                    ));
                    continue;
                }

                if (!$keepExisting) {
                    $existingTerm = $api->searchOne($type, [
                        'vocabulary_id' => $vocabulary->getId(),
                        'local_name' => $element['o:local_name'],
                    ])->getContent();
                    if ($existingTerm) {
                        $this->messenger()->addWarning(new Message(
                            '%s: The term "%s" exists and is skipped.', // @translate
                            $textMessage, $term
                        ));
                        continue;
                    }
                }

                // TODO Check owner of the vocabulary?

                $element['o:vocabulary'] = $vocabulary;
            }
            // New ontology, specified in the post.
            else {
                foreach ($this->reservedPrefixes as $reservedPrefix) {
                    if (preg_match("/$reservedPrefix/", $prefix)) {
                        $hasError = true;
                        $this->messenger()->addError(new Message(
                            '%s: The prefix "%s" is reserved by Omeka.', // @translate
                            $textMessage, $prefix
                        ));
                        continue 2;
                    }
                }

                $element['o:vocabulary'] = $prefix;
            }

            if (empty($element['o:label'])) {
                $hasError = true;
                $this->messenger()->addError(new Message(
                    '%s: The term "%s" must have a label.', // @translate
                    $textMessage, $term
                ));
                continue;
            }

            if ($find = array_search(strtolower($term), $addedTerms)) {
                $hasError = true;
                $this->messenger()->addError(new Message(
                    '%s: The term "%s" is already specified above, row #%d.', // @translate
                    $textMessage, $term, $find
                ));
                continue;
            }

            if ($find = array_search(strtolower($element['o:label']), $addedLabels)) {
                $hasError = true;
                $this->messenger()->addError(new Message(
                    '%s: The label "%s" is already used above, row #%d.', // @translate
                    $textMessage, $element['o:label'], $find
                ));
                continue;
            }

            // TODO Check by label to avoid duplicate.

            $addedTerms[$row] = strtolower($term);
            $addedLabels[$row] = strtolower($element['o:label']);
            // Kept to simplify next processes.
            $element['term'] = $term;
            $result[] = $element;
        }

        return $hasError ? null : $result;
    }

    /**
     * Save an ontology.
     *
     * @param array $ontology
     * @return bool|null
     */
    protected function saveOntology(array $ontology)
    {
        if (empty($ontology) || empty($ontology['o:prefix'])) {
            return;
        }

        $result = $this->api()->create('vocabularies', $ontology);
        if (!$result) {
            $this->messenger()->addError(new Message(
                'An issue occurred when saving the ontology.' // @translate
            ));
            return false;
        }

        return true;
    }

    /**
     * Save resource classes.
     *
     * @param array $resourceClasses
     * @return bool|null
     */
    protected function saveResourceClasses(array $resourceClasses)
    {
        return $this->saveElements($resourceClasses, 'resource_classes');
    }

    /**
     * Save properties.
     *
     * @param array $properties
     * @return bool|null
     */
    protected function saveProperties(array $properties)
    {
        return $this->saveElements($properties, 'properties');
    }

    /**
     * Save elements.
     *
     * The entity manager is used, because the api doesn't allow to create
     * custom elements. The method doesn't flush created elements.
     *
     * @param array $elements
     * @param string $type "resouce_classes" or "properties"
     * @return bool|null
     */
    protected function saveElements(array $elements, $type)
    {
        if (empty($elements)) {
            return;
        }

        $types = [
            'resource_classes' => 'Resource classes', // @translate
            'properties' => 'Properties', // @translate
        ];
        if (!isset($types[$type])) {
            return;
        }

        $entityClasses = [
            'resource_classes' => ResourceClass::class,
            'properties' => Property::class,
        ];

        $entityManager = $this->entityManager;
        $entityClass = $entityClasses[$type];

        $owner = $this->identity();

        foreach ($elements as $element) {
            if (is_string($element['o:vocabulary'])) {
                $element['o:vocabulary'] = $this->ontology;
            }

            $entity = new $entityClass();
            $entity->setOwner($owner);
            $entity->setVocabulary($element['o:vocabulary']);
            $entity->setLocalName($element['o:local_name']);
            $entity->setLabel($element['o:label']);
            $entity->setComment($element['o:comment']);
            $entityManager->persist($entity);
        }

        return true;
    }
}
