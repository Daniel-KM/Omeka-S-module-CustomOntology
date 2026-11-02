<?php declare(strict_types=1);

namespace CustomOntologyTest\Controller\Admin;

use CustomOntologyTest\AbstractCustomOntologyTestCase;

class DeleteTermsTest extends AbstractCustomOntologyTestCase
{
    public function tearDown(): void
    {
        $this->logout();
        $this->loginAsAdmin();
        foreach ($this->api()->search('items', ['fulltext_search' => 'Demo item'])->getContent() as $item) {
            $this->api()->delete('items', $item->id());
        }
        parent::tearDown();
    }

    public function testPageListsTermsAndUsages(): void
    {
        $vocabulary = $this->createDemoVocabulary();
        $this->createDemoItem();

        $this->dispatch('/admin/custom-ontology/vocabulary/' . $vocabulary->id());
        $this->assertResponseStatusCode(200);
        $this->assertQueryCount('#delete-terms-form input[name="csrf"]', 1);
        $this->assertQueryCount('#delete-terms-form input[name="properties[]"]', 1);
        $this->assertQueryCount('#delete-terms-form input[name="resource_classes[]"]', 1);
        $this->assertQuery('#delete-terms-form input[name="properties[]"][value="' . $this->termId('properties', 'demo:authorName') . '"]');
        $this->assertQuery('#delete-terms-form input[name="resource_classes[]"][value="' . $this->termId('resource_classes', 'demo:Map') . '"]');
    }

    public function testBrowseShowsLinkForManagedVocabularyOnly(): void
    {
        $vocabulary = $this->createDemoVocabulary();
        $this->dispatch('/admin/vocabulary');
        $this->assertResponseStatusCode(200);
        $this->assertQueryCount('a.fa-eraser', 1);
        $this->assertQuery('a.fa-eraser[href$="/custom-ontology/vocabulary/' . $vocabulary->id() . '"]');
    }

    public function testTermsPageHasNoLink(): void
    {
        $vocabulary = $this->createDemoVocabulary();
        $this->dispatch('/admin/vocabulary/' . $vocabulary->id() . '/properties');
        $this->assertResponseStatusCode(200);
        $this->assertQueryCount('a.fa-eraser', 0);
    }

    public function testDeleteUnusedTerms(): void
    {
        $vocabulary = $this->createDemoVocabulary();
        $this->createDemoItem();

        $this->postDelete($vocabulary->id(), [
            'properties' => [$this->termId('properties', 'demo:authorName')],
            'resource_classes' => [$this->termId('resource_classes', 'demo:Map')],
        ]);
        $this->assertResponseStatusCode(302);

        $this->assertSame(['demo:author'], array_values($this->terms('properties')));
        $this->assertSame(['demo:Book'], array_values($this->terms('resource_classes')));
    }

    public function testUsedTermsAreNotDeleted(): void
    {
        $vocabulary = $this->createDemoVocabulary();
        $this->createDemoItem();

        $this->postDelete($vocabulary->id(), [
            'properties' => [$this->termId('properties', 'demo:author')],
            'resource_classes' => [$this->termId('resource_classes', 'demo:Book')],
        ]);
        $this->assertResponseStatusCode(200);

        $this->assertCount(2, $this->terms('properties'));
        $this->assertCount(2, $this->terms('resource_classes'));
    }

    public function testTermsOfAnotherVocabularyAreNotDeleted(): void
    {
        $vocabulary = $this->createDemoVocabulary();
        $other = $this->createVocabulary('other', $this->managedNamespaceUri('other'), [], ['size' => 'Size']);
        $otherId = $this->api()->search('properties', ['vocabulary_id' => $other->id()])->getContent()[0]->id();

        $this->postDelete($vocabulary->id(), ['properties' => [$otherId]]);

        $this->assertSame(1, $this->api()->search('properties', ['vocabulary_id' => $other->id()])->getTotalResults());
    }

    public function testInvalidCsrfIsRefused(): void
    {
        $vocabulary = $this->createDemoVocabulary();

        $this->postDelete($vocabulary->id(), [
            'properties' => [$this->termId('properties', 'demo:authorName')],
        ], 'invalid');
        $this->assertResponseStatusCode(200);
        $this->assertMessageContains('Invalid or missing CSRF token', $this->errorMessages());
        $this->assertCount(2, $this->terms('properties'));
    }

    public function testNotManagedVocabularyIsNotFound(): void
    {
        $vocabulary = $this->api()->read('vocabularies', ['prefix' => 'dcterms'])->getContent();
        $this->dispatch('/admin/custom-ontology/vocabulary/' . $vocabulary->id());
        $this->assertResponseStatusCode(404);
    }

    public function testMissingVocabularyIsNotFound(): void
    {
        $this->dispatch('/admin/custom-ontology/vocabulary/999999');
        $this->assertResponseStatusCode(404);
    }

    public function testResearcherIsDenied(): void
    {
        $vocabulary = $this->createDemoVocabulary();
        $this->loginAs($this->createUser('researcher@example.org', 'researcher'));
        $this->expectException(\Omeka\Mvc\Exception\PermissionDeniedException::class);
        $this->dispatch('/admin/custom-ontology/vocabulary/' . $vocabulary->id());
    }

    public function testSiteAdminCannotDeleteTermsOfVocabularyOfOther(): void
    {
        $vocabulary = $this->createDemoVocabulary();
        $this->loginAs($this->createUser('siteadmin@example.org', 'site_admin'));

        $this->dispatch('/admin/vocabulary');
        $this->assertQueryCount('a.fa-eraser', 0);

        $this->dispatch('/admin/custom-ontology/vocabulary/' . $vocabulary->id());
        $this->assertResponseStatusCode(403);
    }

    public function testSiteAdminCanDeleteTermsOfOwnVocabulary(): void
    {
        $user = $this->createUser('siteadmin@example.org', 'site_admin');
        $this->loginAs($user);
        $vocabulary = $this->createDemoVocabulary();
        $propertyId = $this->termId('properties', 'demo:authorName');

        $this->postDelete($vocabulary->id(), ['properties' => [$propertyId]]);
        $this->assertResponseStatusCode(302);

        $this->logout();
        $this->loginAsAdmin();
        $this->assertSame(['demo:author'], array_values($this->terms('properties')));
    }

    protected function createDemoVocabulary(): \Omeka\Api\Representation\VocabularyRepresentation
    {
        return $this->createVocabulary(
            'demo',
            $this->managedNamespaceUri('demo'),
            ['Book' => 'Book', 'Map' => 'Map'],
            ['author' => 'Author', 'authorName' => 'Author name']
        );
    }

    protected function createDemoItem(): void
    {
        $this->api()->create('items', [
            'o:resource_class' => ['o:id' => $this->termId('resource_classes', 'demo:Book')],
            'demo:author' => [[
                'type' => 'literal',
                'property_id' => $this->termId('properties', 'demo:author'),
                '@value' => 'Demo item',
            ]],
        ]);
    }

    /**
     * Post the form to delete terms, with a csrf token created after the reset.
     */
    protected function postDelete(int $vocabularyId, array $data, ?string $csrf = null): void
    {
        $this->reset();
        $this->getApplication();
        if ($this->dispatchUserId) {
            $this->writeIdentity($this->dispatchUserId);
        } else {
            $this->loginAsAdmin();
        }
        $data['csrf'] = $csrf ?? (new \Laminas\Form\Element\Csrf('csrf'))->getValue();
        $this->url('/admin/custom-ontology/vocabulary/' . $vocabularyId, 'POST', $data);
        $this->getApplication()->run();
    }

    protected function terms(string $type): array
    {
        $vocabulary = $this->api()->read('vocabularies', ['prefix' => 'demo'])->getContent();
        $terms = [];
        foreach ($this->api()->search($type, ['vocabulary_id' => $vocabulary->id(), 'sort_by' => 'local_name'])->getContent() as $term) {
            $terms[$term->id()] = $term->term();
        }
        return $terms;
    }

    protected function termId(string $type, string $term): int
    {
        return (int) array_search($term, $this->terms($type), true);
    }
}
