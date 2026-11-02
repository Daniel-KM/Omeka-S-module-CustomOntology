<?php declare(strict_types=1);

namespace CustomOntologyTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * Base test case for Custom Ontology.
 *
 * Every test starts and ends with the vocabularies installed with Omeka only:
 * the other ones are removed before and after each test, so a failed test does
 * not leave a vocabulary that would make the next ones fail.
 */
abstract class AbstractCustomOntologyTestCase extends AbstractHttpControllerTestCase
{
    /**
     * Prefixes of the vocabularies installed with Omeka.
     */
    protected const CORE_PREFIXES = ['dcterms', 'dctype', 'bibo', 'foaf'];

    /**
     * @var string[]
     */
    protected $initialPrefixes = [];

    /**
     * Emails of the users created by the test.
     *
     * @var string[]
     */
    protected $createdUsers = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->removeTestVocabularies();
        $this->initialPrefixes = $this->vocabularyPrefixes();
    }

    public function tearDown(): void
    {
        $this->removeTestVocabularies();
        $entityManager = $this->getEntityManager();
        foreach ($this->createdUsers as $email) {
            $user = $entityManager->getRepository(\Omeka\Entity\User::class)->findOneBy(['email' => $email]);
            if ($user) {
                $entityManager->remove($user);
            }
        }
        $entityManager->flush();
        parent::tearDown();
    }

    protected function services(): \Laminas\ServiceManager\ServiceLocatorInterface
    {
        return $this->getApplication()->getServiceManager();
    }

    protected function createUser(string $email, string $role): \Omeka\Entity\User
    {
        $entityManager = $this->getEntityManager();
        $user = new \Omeka\Entity\User();
        $user->setEmail($email);
        $user->setName($email);
        $user->setRole($role);
        $user->setIsActive(true);
        $user->setPassword('password');
        $entityManager->persist($user);
        $entityManager->flush();
        $this->createdUsers[] = $email;
        return $user;
    }

    protected function removeTestVocabularies(): void
    {
        // A test may have logged in as another user.
        $this->logout();
        $this->loginAsAdmin();
        $api = $this->api();
        foreach (array_diff($this->vocabularyPrefixes(), self::CORE_PREFIXES) as $prefix) {
            $api->delete('vocabularies', ['prefix' => $prefix]);
        }
    }

    /**
     * @return string[]
     */
    protected function vocabularyPrefixes(): array
    {
        $prefixes = [];
        foreach ($this->api()->search('vocabularies')->getContent() as $vocabulary) {
            $prefixes[] = $vocabulary->prefix();
        }
        return $prefixes;
    }

    /**
     * Namespace uri managed by the module for a prefix (route "ns/prefix").
     */
    protected function managedNamespaceUri(string $prefix): string
    {
        $url = $this->getApplication()->getServiceManager()->get('ViewHelperManager')->get('url');
        return $url('ns/prefix', ['prefix' => $prefix], ['force_canonical' => true]) . '/';
    }

    /**
     * Create a vocabulary directly via the api, with optional classes and
     * properties, each as [localName => label].
     */
    protected function createVocabulary(
        string $prefix,
        string $namespaceUri,
        array $classes = [],
        array $properties = []
    ): \Omeka\Api\Representation\VocabularyRepresentation {
        $data = [
            'o:prefix' => $prefix,
            'o:namespace_uri' => $namespaceUri,
            'o:label' => ucfirst($prefix),
            'o:class' => [],
            'o:property' => [],
        ];
        foreach ($classes as $localName => $label) {
            $data['o:class'][] = ['o:local_name' => $localName, 'o:label' => $label];
        }
        foreach ($properties as $localName => $label) {
            $data['o:property'][] = ['o:local_name' => $localName, 'o:label' => $label];
        }
        return $this->api()->create('vocabularies', $data)->getContent();
    }

    /**
     * Post the form of the module.
     *
     * @param array $ontology Keys o:namespace_uri, o:prefix, o:label, o:comment.
     * @param string $classes One class by line: "term, label, comment".
     * @param string $properties One property by line: "term, label, comment".
     * @param string $submit "submit" to save or "download" to get the turtle.
     * @param string|null $csrf Null for a valid csrf token, an empty string to
     * omit it, else the token to send.
     */
    protected function postForm(
        array $ontology = [],
        string $classes = '',
        string $properties = '',
        string $submit = 'submit',
        ?string $csrf = null
    ): void {
        // Each dispatch resets the application and its session, so the token
        // is created after the reset, then the request is run directly.
        $this->reset();
        $this->getApplication();
        if ($this->dispatchUserId) {
            $this->writeIdentity($this->dispatchUserId);
        } else {
            $this->loginAsAdmin();
        }
        $data = [
            'ontology_fieldset' => $ontology + [
                'o:namespace_uri' => '',
                'o:prefix' => '',
                'o:label' => '',
                'o:comment' => '',
            ],
            'resource_classes_fieldset' => ['resource_classes' => $classes],
            'properties_fieldset' => ['properties' => $properties],
            'submit' => $submit,
        ];
        if ($csrf === null) {
            $data['csrf'] = (new \Laminas\Form\Element\Csrf('csrf'))->getValue();
        } elseif ($csrf !== '') {
            $data['csrf'] = $csrf;
        }
        $this->url('/admin/custom-ontology', 'POST', $data);
        $this->getApplication()->run();
    }

    /**
     * Messages displayed in the page.
     *
     * The layout renders and consumes the messages of the messenger, so they
     * are read in the response.
     *
     * @param string $type "error", "warning" or "success".
     * @return string[]
     */
    protected function messages(string $type): array
    {
        $content = (string) $this->getResponse()->getContent();
        if ($content === '') {
            return [];
        }
        $dom = new \Laminas\Dom\Query($content);
        $messages = [];
        foreach ($dom->execute('ul.messages li.' . $type) as $node) {
            $messages[] = trim($node->textContent);
        }
        return $messages;
    }

    protected function errorMessages(): array
    {
        return $this->messages('error');
    }

    protected function warningMessages(): array
    {
        return $this->messages('warning');
    }

    protected function successMessages(): array
    {
        return $this->messages('success');
    }

    protected function assertMessageContains(string $needle, array $messages): void
    {
        foreach ($messages as $message) {
            if (strpos($message, $needle) !== false) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail(sprintf('No message contains "%s". Messages: %s', $needle, json_encode($messages, 320)));
    }
}
