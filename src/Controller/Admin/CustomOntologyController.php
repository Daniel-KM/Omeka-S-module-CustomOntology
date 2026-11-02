<?php declare(strict_types=1);

namespace CustomOntology\Controller\Admin;

use CustomOntology\Form\CustomOntologyForm;
use CustomOntology\Form\DeleteTermsForm;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Entity\Property;
use Omeka\Entity\ResourceClass;
use Omeka\Entity\Vocabulary;
use Omeka\Mvc\Exception\NotFoundException;
use Omeka\Mvc\Exception\PermissionDeniedException;
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
        $isSubmit = $action !== 'download';
        $isDownload = $action === 'download';

        // TODO Move validation inside form.
        $valid = [];
        $valid['ontology'] = $this->validateOntology($params['ontology_fieldset'], $isSubmit);
        if ($valid['ontology'] && $valid['ontology']['o:prefix']) {
            $this->ontologyPrefix = $valid['ontology']['o:prefix'];
        }

        $valid['resource_classes'] = $this->validateResourceClasses(
            $params['resource_classes_fieldset']['resource_classes'],
            $isDownload
        );
        $valid['properties'] = $this->validateProperties(
            $params['properties_fieldset']['properties'],
            $isDownload
        );

        if ($valid['ontology'] === null
            || $valid['resource_classes'] === null
            || $valid['properties'] === null
        ) {
            $this->messenger()->addWarning(
                'An error was found. Turtle cannot be created. Nothing was imported.' // @translate
            );
            return $view;
        }

        if (!array_filter(array_map('array_filter', $valid))) {
            $this->messenger()->addWarning('Nothing to process.'); // @translate
            return $view;
        }

        if ($isDownload) {
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
     * List the terms of a vocabulary of the module and delete the unused ones.
     *
     * Only the vocabularies managed by the module can be processed, and a term
     * is deleted only when it is not used anywhere (resources, templates,
     * settings and search configs).
     */
    public function vocabularyAction()
    {
        try {
            /** @var \Omeka\Entity\Vocabulary $vocabulary */
            $vocabulary = $this->api()->read(
                'vocabularies',
                (int) $this->params('id'),
                [],
                ['responseContent' => 'resource']
            )->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            throw new NotFoundException;
        }

        if (!$this->isManagedVocabulary($vocabulary->getPrefix(), $vocabulary->getNamespaceUri())) {
            throw new NotFoundException;
        }

        if (!$this->isAllowedToDeleteTerms($vocabulary)) {
            throw new PermissionDeniedException(sprintf(
                'Permission denied for the current user to delete the terms of the vocabulary "%s".', // @translate
                $vocabulary->getPrefix()
            ));
        }

        $terms = $this->vocabularyTerms($vocabulary);
        $usages = [
            'resource_classes' => $this->termUsages('resource_classes', $terms['resource_classes']),
            'properties' => $this->termUsages('properties', $terms['properties']),
        ];

        /** @var \CustomOntology\Form\DeleteTermsForm $form */
        $form = $this->getForm(DeleteTermsForm::class);
        foreach ($terms as $type => $list) {
            $unused = [];
            foreach ($list as $id => $term) {
                if (!$this->plugin('termUsages')->isUsed($usages[$type][$id])) {
                    $unused[$id] = $term;
                }
            }
            $form->get($type)->setValueOptions($unused);
        }

        if ($this->getRequest()->isPost()) {
            $form->setData($this->getRequest()->getPost());
            if (!$form->isValid()) {
                $this->messenger()->addFormErrors($form);
            } else {
                $data = $form->getData();
                $deleted = $this->deleteUnusedTerms($vocabulary, [
                    'resource_classes' => array_map('intval', $data['resource_classes'] ?? []),
                    'properties' => array_map('intval', $data['properties'] ?? []),
                ]);
                if ($deleted === null) {
                    $this->messenger()->addWarning('No term selected.'); // @translate
                } else {
                    return $this->redirect()->toRoute(null, [], true);
                }
            }
        }

        return new ViewModel([
            'vocabulary' => $this->api()->read('vocabularies', $vocabulary->getId())->getContent(),
            'terms' => $terms,
            'usages' => $usages,
            'form' => $form,
        ]);
    }

    /**
     * The terms are deleted via the api, but the vocabulary is checked too,
     * because the core restricts the update of the vocabulary to its owner for
     * a site admin, but not the deletion of its terms.
     */
    protected function isAllowedToDeleteTerms(Vocabulary $vocabulary): bool
    {
        return $this->userIsAllowed($vocabulary, 'update')
            && $this->userIsAllowed(\Omeka\Api\Adapter\ResourceClassAdapter::class, 'delete')
            && $this->userIsAllowed(\Omeka\Api\Adapter\PropertyAdapter::class, 'delete');
    }

    /**
     * @return array Terms ("prefix:localName") by id, by type.
     */
    protected function vocabularyTerms(Vocabulary $vocabulary): array
    {
        $prefix = $vocabulary->getPrefix();
        $terms = ['resource_classes' => [], 'properties' => []];
        foreach ($vocabulary->getResourceClasses() as $resourceClass) {
            $terms['resource_classes'][$resourceClass->getId()] = $prefix . ':' . $resourceClass->getLocalName();
        }
        foreach ($vocabulary->getProperties() as $property) {
            $terms['properties'][$property->getId()] = $prefix . ':' . $property->getLocalName();
        }
        foreach ($terms as &$list) {
            asort($list);
        }
        unset($list);
        return $terms;
    }

    /**
     * Delete the selected terms that belong to the vocabulary and that are
     * still unused: they may have been used since the display of the form.
     *
     * @param array $selected Ids by type.
     * @return array|null Deleted terms, or null when nothing was selected.
     */
    protected function deleteUnusedTerms(Vocabulary $vocabulary, array $selected): ?array
    {
        if (!array_filter($selected)) {
            return null;
        }

        $terms = $this->vocabularyTerms($vocabulary);
        $deleted = [];
        $skipped = [];
        foreach ($selected as $type => $ids) {
            $list = array_intersect_key($terms[$type], array_flip($ids));
            $usages = $this->termUsages($type, $list);
            foreach ($list as $id => $term) {
                if ($this->plugin('termUsages')->isUsed($usages[$id])) {
                    $skipped[] = $term;
                    continue;
                }
                $this->api()->delete($type, $id);
                $deleted[] = $term;
            }
        }

        if ($deleted) {
            $this->messenger()->addSuccess(new Message(
                '%1$d terms were deleted: %2$s.', // @translate
                count($deleted), implode(', ', $deleted)
            ));
        }
        if ($skipped) {
            $this->messenger()->addWarning(new Message(
                'These terms are used now and were not deleted: %s.', // @translate
                implode(', ', $skipped)
            ));
        }
        return $deleted;
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
        $ontology['o:namespace_uri'] = (string) $ontology['o:namespace_uri'];
        $ontology['o:label'] = (string) $ontology['o:label'];
        // Without a final separator, the local names would be appended to the
        // last segment of the namespace.
        if (!in_array(substr($ontology['o:namespace_uri'], -1), ['/', '#'], true)) {
            $this->messenger()->addError('The namespace uri must end with "/" or "#".'); // @translate
            return;
        }
        if (strtolower($ontology['o:prefix']) !== $ontology['o:prefix']) {
            $this->messenger()->addError('The prefix of the ontology must be lowercase.'); // @translate
            return;
        }

        if (!preg_match('~^[a-z][a-z0-9]*$~', $ontology['o:prefix'])) {
            $this->messenger()->addError('The prefix must start with an alphabetic character and must contain only alphanumeric characters.'); // @translate
            return;
        }

        if ($this->isReservedPrefix($ontology['o:prefix'])) {
            $this->messenger()->addError(new Message(
                'The prefix "%s" is reserved by Omeka.', // @translate
                $ontology['o:prefix']
            ));
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
        try {
            $vocabulary = $api->read('vocabularies', ['prefix' => $ontology['o:prefix']])->getContent();
        } catch (\Throwable $e) {
            $vocabulary = null;
        }
        if (!empty($vocabulary)) {
            $this->messenger()->addWarning(new Message(
                'An ontology exists for the prefix "%s".', // @translate
                $ontology['o:prefix']
            ));
            return;
        }

        try {
            $vocabulary = $api->read('vocabularies', ['namespaceUri' => $ontology['o:namespace_uri']])->getContent();
        } catch (\Throwable $e) {
            $vocabulary = null;
        }
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
     * Check if a prefix is reserved by Omeka.
     *
     * The api refuses the reserved prefixes too, but without the reason.
     */
    protected function isReservedPrefix(string $prefix): bool
    {
        foreach ($this->reservedPrefixes as $reservedPrefix) {
            if (preg_match("/$reservedPrefix/", $prefix)) {
                return true;
            }
        }
        return false;
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
     * @param string $type "resource_classes" or "properties"
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

        /**
         * @var \Omeka\Mvc\Controller\Plugin\Api $api
         */
        $result = [];
        $api = $this->api();
        $hasError = false;
        $addedTerms = [];
        $addedLabels = [];

        // The strtr() allows to fix Apple copy/paste.
        $elements = array_filter(array_map('trim', explode("\n", strtr($elements, ["\r\n" => "\n", "\n\r" => "\n", "\r" => "\n"]))));

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
            try {
                $vocabulary = $api->read('vocabularies', ['prefix' => $prefix], [], ['responseContent' => 'resource'])->getContent();
            } catch (\Throwable $e) {
                $vocabulary = null;
            }
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
                    try {
                        $existingTerm = $api->read($type, [
                            'vocabulary' => $vocabulary->getId(),
                            'localName' => $element['o:local_name'],
                        ])->getContent();
                    } catch (\Throwable $e) {
                        $existingTerm = null;
                    }
                    if ($existingTerm) {
                        $this->messenger()->addWarning(new Message(
                            '%s: The term "%s" exists and is skipped.', // @translate
                            $textMessage, $term
                        ));
                        continue;
                    }
                }

                // The terms are saved via the entity manager, so the rights are
                // checked here.
                if (!$this->userIsAllowed($vocabulary, 'update')) {
                    $hasError = true;
                    $this->messenger()->addError(new Message(
                        '%s: You are not allowed to add a term to the vocabulary "%s".', // @translate
                        $textMessage, $prefix
                    ));
                    continue;
                }

                $element['o:vocabulary'] = $vocabulary;
            }
            // New ontology, specified in the post.
            else {
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
     * @param string $type "resource_classes" or "properties"
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
