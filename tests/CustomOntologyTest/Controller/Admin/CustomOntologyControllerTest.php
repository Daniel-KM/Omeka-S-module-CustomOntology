<?php declare(strict_types=1);

namespace CustomOntologyTest\Controller\Admin;

use CustomOntologyTest\AbstractCustomOntologyTestCase;

class CustomOntologyControllerTest extends AbstractCustomOntologyTestCase
{
    public function testFormIsDisplayed(): void
    {
        $this->dispatch('/admin/custom-ontology');
        $this->assertResponseStatusCode(200);
        $this->assertQuery('#custom-ontology-form');
        $this->assertQuery('textarea[name="resource_classes_fieldset[resource_classes]"]');
        $this->assertQuery('textarea[name="properties_fieldset[properties]"]');
    }

    public function testCreateOntologyWithClassesAndProperties(): void
    {
        $namespaceUri = $this->managedNamespaceUri('myonto');
        $this->postForm(
            [
                'o:namespace_uri' => $namespaceUri,
                'o:prefix' => 'myonto',
                'o:label' => 'My ontology',
                'o:comment' => 'An ontology for tests.',
            ],
            "myonto:Letter, Letter, A written message.\nmyonto:Postcard, Postcard",
            "myonto:sender, Sender, Who sent it.\nmyonto:stampNumber, Stamp number"
        );
        $this->assertResponseStatusCode(200);
        $this->assertSame([], $this->errorMessages());

        /** @var \Omeka\Api\Representation\VocabularyRepresentation $vocabulary */
        $vocabulary = $this->api()->read('vocabularies', ['prefix' => 'myonto'])->getContent();
        $this->assertSame($namespaceUri, $vocabulary->namespaceUri());
        $this->assertSame('My ontology', $vocabulary->label());
        $this->assertSame('An ontology for tests.', $vocabulary->comment());

        $classes = $this->api()->search('resource_classes', ['vocabulary_id' => $vocabulary->id(), 'sort_by' => 'local_name'])->getContent();
        $this->assertSame(['Letter', 'Postcard'], array_map(fn ($c) => $c->localName(), $classes));
        $this->assertSame('A written message.', $classes[0]->comment());

        $properties = $this->api()->search('properties', ['vocabulary_id' => $vocabulary->id(), 'sort_by' => 'local_name'])->getContent();
        $this->assertSame(['sender', 'stampNumber'], array_map(fn ($p) => $p->localName(), $properties));
        $this->assertSame('Stamp number', $properties[1]->label());

        // The terms are owned by the current user.
        $property = $this->getEntityManager()->find(\Omeka\Entity\Property::class, $properties[0]->id());
        $this->assertSame('admin@example.com', $property->getOwner()->getEmail());

        $this->assertMessageContains('2 resource classes have been created: myonto:Letter, myonto:Postcard.', $this->successMessages());
        $this->assertMessageContains('2 properties have been created: myonto:sender, myonto:stampNumber.', $this->successMessages());
    }

    public function testAddTermsToAnExistingCustomVocabulary(): void
    {
        $this->createVocabulary('mine', $this->managedNamespaceUri('mine'), ['Book' => 'Book']);

        $this->postForm([], 'mine:Map, Map', 'mine:scale, Scale');
        $this->assertSame([], $this->errorMessages());

        $vocabulary = $this->api()->read('vocabularies', ['prefix' => 'mine'])->getContent();
        $this->assertSame(2, $vocabulary->resourceClassCount());
        $this->assertSame(1, $vocabulary->propertyCount());
    }

    public function testExistingTermsAreSkippedInsteadOfDuplicated(): void
    {
        $this->createVocabulary('mine', $this->managedNamespaceUri('mine'), [], ['scale' => 'Scale']);

        $this->postForm([], '', "mine:scale, Scale again\nmine:width, Width");

        $vocabulary = $this->api()->read('vocabularies', ['prefix' => 'mine'])->getContent();
        $this->assertSame(2, $vocabulary->propertyCount());
        $this->assertSame([], $this->errorMessages());
    }

    /**
     * @dataProvider invalidOntologyProvider
     */
    public function testInvalidOntologyIsRejected(array $ontology, string $expectedError): void
    {
        $this->postForm($ontology, '', 'other:term, Term');
        $this->assertMessageContains($expectedError, $this->errorMessages());
        $this->assertMessageContains('Nothing was imported.', $this->warningMessages());
        $this->assertSame($this->initialPrefixes, $this->vocabularyPrefixes());
    }

    public function invalidOntologyProvider(): array
    {
        return [
            'namespace without prefix' => [
                ['o:namespace_uri' => 'http://example.org/ns/'],
                'there is no prefix',
            ],
            'prefix without namespace' => [
                ['o:prefix' => 'abc', 'o:label' => 'Abc'],
                'there is no namespace uri',
            ],
            'namespace without final separator' => [
                ['o:prefix' => 'abc', 'o:namespace_uri' => 'http://example.org/ns/abc', 'o:label' => 'Abc'],
                'must end with "/" or "#"',
            ],
            'uppercase prefix' => [
                ['o:prefix' => 'Abc', 'o:namespace_uri' => 'http://example.org/ns/', 'o:label' => 'Abc'],
                'must be lowercase',
            ],
            'prefix with a dash' => [
                ['o:prefix' => 'ab-c', 'o:namespace_uri' => 'http://example.org/ns/', 'o:label' => 'Abc'],
                'only alphanumeric characters',
            ],
            'prefix starting with a digit' => [
                ['o:prefix' => '1abc', 'o:namespace_uri' => 'http://example.org/ns/', 'o:label' => 'Abc'],
                'must start with an alphabetic character',
            ],
            'no label' => [
                ['o:prefix' => 'abc', 'o:namespace_uri' => 'http://example.org/ns/'],
                'A label is required',
            ],
        ];
    }

    public function testExistingPrefixOrNamespaceIsRejected(): void
    {
        $this->postForm(['o:prefix' => 'foaf', 'o:namespace_uri' => 'http://example.org/other/', 'o:label' => 'Foaf']);
        $this->assertMessageContains('An ontology exists for the prefix "foaf"', $this->warningMessages());

        $this->postForm(['o:prefix' => 'newfoaf', 'o:namespace_uri' => 'http://xmlns.com/foaf/0.1/', 'o:label' => 'Foaf']);
        $this->assertMessageContains('An ontology exists for the namespace uri', $this->warningMessages());

        $this->assertSame($this->initialPrefixes, $this->vocabularyPrefixes());
    }

    /**
     * @dataProvider invalidTermProvider
     */
    public function testInvalidTermsAreRejected(string $classes, string $properties, string $expectedError): void
    {
        $this->createVocabulary('mine', $this->managedNamespaceUri('mine'));

        $this->postForm([], $classes, $properties);
        $this->assertMessageContains($expectedError, $this->errorMessages());

        $vocabulary = $this->api()->read('vocabularies', ['prefix' => 'mine'])->getContent();
        $this->assertSame(0, $vocabulary->resourceClassCount() + $vocabulary->propertyCount());
    }

    public function invalidTermProvider(): array
    {
        return [
            'no prefix' => ['Book, Book', '', 'must be formatted "prefix:LocalName"'],
            'empty local name' => ['', 'mine:, Empty', 'must be formatted "prefix:localName"'],
            'class in lower case' => ['mine:book, Book', '', 'must start with a upper-case letter'],
            'property in upper case' => ['', 'mine:Title, Title', 'must start with a lower-case letter'],
            'forbidden character' => ['', 'mine:my.title, Title', 'contain only alphanumeric characters'],
            'unknown prefix' => ['', 'unknown:title, Title', 'No ontology exists for prefix "unknown"'],
            'no label' => ['', 'mine:title', 'must have a label'],
            'duplicate term' => ['', "mine:title, Title\nmine:title, Other", 'is already specified above, row #1'],
            'duplicate label' => ['', "mine:title, Title\nmine:name, title", 'is already used above, row #1'],
            'dublin core' => ['', 'dcterms:myTitle, My title', 'forbids to add a term to the default Dublin Core'],
        ];
    }

    /**
     * @dataProvider reservedPrefixProvider
     */
    public function testReservedPrefixIsRejected(string $prefix, string $properties): void
    {
        $this->postForm(
            ['o:prefix' => $prefix, 'o:namespace_uri' => 'http://example.org/' . $prefix . '/', 'o:label' => 'Reserved'],
            '',
            $properties
        );
        $this->assertResponseStatusCode(200);
        $this->assertMessageContains('The prefix "' . $prefix . '" is reserved by Omeka.', $this->errorMessages());
        $this->assertMessageContains('Nothing was imported.', $this->warningMessages());
        $this->assertSame($this->initialPrefixes, $this->vocabularyPrefixes());
    }

    public function reservedPrefixProvider(): array
    {
        return [
            'o without terms' => ['o', ''],
            'o with terms' => ['o', 'o:myTitle, My title'],
            'time' => ['time', ''],
            'cnt' => ['cnt', 'cnt:chars, Chars'],
        ];
    }

    /**
     * A prefix that only starts like a reserved one is allowed.
     */
    public function testPrefixStartingLikeReservedIsAllowed(): void
    {
        $this->postForm(
            ['o:prefix' => 'omk', 'o:namespace_uri' => $this->managedNamespaceUri('omk'), 'o:label' => 'Omk'],
            '',
            'omk:title, Title'
        );
        $this->assertSame([], $this->errorMessages());
        $this->assertContains('omk', $this->vocabularyPrefixes());
    }

    public function testCsrfTokenIsRendered(): void
    {
        $this->dispatch('/admin/custom-ontology');
        $this->assertQuery('#custom-ontology-form input[type="hidden"][name="csrf"]');
    }

    /**
     * @dataProvider csrfProvider
     */
    public function testPostWithoutValidCsrfIsRefused(string $submit, string $csrf, string $expectedError): void
    {
        $this->postForm(
            ['o:prefix' => 'nocsrf', 'o:namespace_uri' => $this->managedNamespaceUri('nocsrf'), 'o:label' => 'No csrf'],
            '',
            'nocsrf:title, Title',
            $submit,
            $csrf
        );
        $this->assertResponseStatusCode(200);
        // No file is returned.
        $this->assertFalse($this->getResponse()->getHeaders()->has('Content-Disposition'));
        $this->assertMessageContains($expectedError, $this->errorMessages());
        $this->assertSame($this->initialPrefixes, $this->vocabularyPrefixes());
    }

    public function csrfProvider(): array
    {
        return [
            'save, missing' => ['submit', '', "Value is required and can't be empty"],
            'save, invalid' => ['submit', 'invalid-token', 'Invalid or missing CSRF token'],
            'download, missing' => ['download', '', "Value is required and can't be empty"],
            'download, invalid' => ['download', 'invalid-token', 'Invalid or missing CSRF token'],
        ];
    }

    /**
     * A single invalid line prevents the import of the valid ones.
     */
    public function testOneErrorPreventsAnyImport(): void
    {
        $this->createVocabulary('mine', $this->managedNamespaceUri('mine'));

        $this->postForm([], 'mine:Map, Map', "mine:scale, Scale\nmine:Bad, Bad");
        $this->assertMessageContains('Nothing was imported.', $this->warningMessages());

        $vocabulary = $this->api()->read('vocabularies', ['prefix' => 'mine'])->getContent();
        $this->assertSame(0, $vocabulary->resourceClassCount() + $vocabulary->propertyCount());
    }

    /**
     * Line endings of other systems (copy/paste) and blank lines are accepted.
     */
    public function testLineEndingsAndBlankLines(): void
    {
        $this->createVocabulary('mine', $this->managedNamespaceUri('mine'));

        $this->postForm([], '', "mine:alpha, Alpha\r\n\r\nmine:beta, Beta\rmine:gamma, Gamma\n  \n");
        $this->assertSame([], $this->errorMessages());

        $vocabulary = $this->api()->read('vocabularies', ['prefix' => 'mine'])->getContent();
        $this->assertSame(3, $vocabulary->propertyCount());
    }

    public function testEmptyFormDoesNothing(): void
    {
        $this->postForm();
        $this->assertMessageContains('Nothing to process.', $this->warningMessages());
        $this->assertSame($this->initialPrefixes, $this->vocabularyPrefixes());
    }

    public function testDownloadReturnsTurtleWithoutSaving(): void
    {
        $namespaceUri = $this->managedNamespaceUri('dlonto');
        $this->postForm(
            ['o:namespace_uri' => $namespaceUri, 'o:prefix' => 'dlonto', 'o:label' => 'Download "test"'],
            'dlonto:Thing, Thing',
            'dlonto:size, Size, Size in "cm".',
            'download'
        );

        $this->assertResponseStatusCode(200);
        $this->assertResponseHeaderContains('Content-Type', 'text/turtle');
        $this->assertResponseHeaderContains('Content-Disposition', 'attachment; filename="dlonto.ttl"');

        $turtle = $this->getResponse()->getContent();
        $this->assertStringContainsString('@prefix dlonto: <' . $namespaceUri . '> .', $turtle);
        $this->assertStringContainsString('dcterms:title "Download \"test\""', $turtle);
        $this->assertStringContainsString('dlonto:Thing a rdfs:Class', $turtle);
        $this->assertStringContainsString('rdfs:comment "Size in \"cm\"."', $turtle);

        // Valid turtle.
        $graph = new \EasyRdf\Graph();
        $this->assertGreaterThan(0, $graph->parse($turtle, 'turtle'));

        // Nothing is saved.
        $this->assertSame($this->initialPrefixes, $this->vocabularyPrefixes());
    }

    /**
     * A download may include the terms that exist already.
     */
    public function testDownloadKeepsExistingTerms(): void
    {
        $this->createVocabulary('mine', $this->managedNamespaceUri('mine'), [], ['scale' => 'Scale']);

        $this->postForm([], '', 'mine:scale, Scale', 'download');
        $this->assertResponseHeaderContains('Content-Disposition', 'attachment; filename="ontology.ttl"');
        $this->assertStringContainsString('mine:scale a rdf:Property', $this->getResponse()->getContent());
    }

    public function testRequiresRightToCreateVocabularies(): void
    {
        $user = $this->createUser('researcher@example.org', 'researcher');
        $this->loginAs($user);
        $this->expectException(\Omeka\Mvc\Exception\PermissionDeniedException::class);
        $this->dispatch('/admin/custom-ontology');
    }

    /**
     * A site admin cannot add terms to a vocabulary owned by another user.
     */
    public function testSiteAdminCannotAddTermsToVocabularyOfOther(): void
    {
        $this->createVocabulary('mine', $this->managedNamespaceUri('mine'));
        $this->loginAs($this->createUser('siteadmin@example.org', 'site_admin'));

        $this->postForm([], '', 'mine:scale, Scale');
        $this->assertMessageContains('You are not allowed to add a term to the vocabulary "mine"', $this->errorMessages());

        $this->logout();
        $vocabulary = $this->api()->read('vocabularies', ['prefix' => 'mine'])->getContent();
        $this->assertSame(0, $vocabulary->propertyCount());
    }

    /**
     * A site admin can add terms to its own vocabulary.
     */
    public function testSiteAdminCanAddTermsToOwnVocabulary(): void
    {
        $this->loginAs($this->createUser('siteadmin@example.org', 'site_admin'));

        $this->postForm(
            ['o:prefix' => 'own', 'o:namespace_uri' => $this->managedNamespaceUri('own'), 'o:label' => 'Own'],
            '',
            'own:scale, Scale'
        );
        $this->postForm([], '', 'own:width, Width');
        $this->assertSame([], $this->errorMessages());

        $this->logout();
        $vocabulary = $this->api()->read('vocabularies', ['prefix' => 'own'])->getContent();
        $this->assertSame(2, $vocabulary->propertyCount());
        $this->assertSame('siteadmin@example.org', $vocabulary->owner()->email());
    }
}
