<?php declare(strict_types=1);

namespace CustomOntologyTest;

class ModuleTest extends AbstractCustomOntologyTestCase
{
    public function testButtonIsAddedToVocabularyBrowse(): void
    {
        $this->dispatch('/admin/vocabulary');
        $this->assertResponseStatusCode(200);
        $this->assertQueryContentContains('#page-actions a.button', 'Create a vocabulary');
    }

    public function testButtonIsNotAddedToOtherVocabularyPages(): void
    {
        $vocabulary = $this->api()->read('vocabularies', ['prefix' => 'dcterms'])->getContent();
        $this->dispatch('/admin/vocabulary/' . $vocabulary->id() . '/properties');
        $this->assertResponseStatusCode(200);
        $this->assertStringNotContainsString('Create a vocabulary', $this->getResponse()->getContent());
    }

    public function testAdminMenuLink(): void
    {
        $this->dispatch('/admin');
        $this->assertResponseStatusCode(200);
        $this->assertQuery('a[href$="/admin/custom-ontology"]');
    }
}
