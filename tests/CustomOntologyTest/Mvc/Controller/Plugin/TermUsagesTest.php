<?php declare(strict_types=1);

namespace CustomOntologyTest\Mvc\Controller\Plugin;

use CustomOntologyTest\AbstractCustomOntologyTestCase;

class TermUsagesTest extends AbstractCustomOntologyTestCase
{
    /**
     * @var \CustomOntology\Mvc\Controller\Plugin\TermUsages
     */
    protected $termUsages;

    /**
     * @var \Omeka\Api\Representation\VocabularyRepresentation
     */
    protected $vocabulary;

    public function setUp(): void
    {
        parent::setUp();
        $this->termUsages = $this->services()->get('ControllerPluginManager')->get('termUsages');
        $this->vocabulary = $this->createVocabulary(
            'demo',
            $this->managedNamespaceUri('demo'),
            ['Book' => 'Book', 'Map' => 'Map'],
            ['author' => 'Author', 'authorName' => 'Author name']
        );
    }

    public function tearDown(): void
    {
        $settings = $this->services()->get('Omeka\Settings');
        $settings->delete('customontology_test');
        $settings->delete('customontology_test_property');
        foreach ($this->api()->search('sites', ['slug' => 'demo-site'])->getContent() as $site) {
            $this->api()->delete('sites', $site->id());
        }
        foreach ($this->api()->search('resource_templates', ['label' => 'Demo template'])->getContent() as $template) {
            $this->api()->delete('resource_templates', $template->id());
        }
        foreach ($this->api()->search('items', ['fulltext_search' => 'Demo item'])->getContent() as $item) {
            $this->api()->delete('items', $item->id());
        }
        parent::tearDown();
    }

    public function testUnusedTerms(): void
    {
        foreach ($this->termUsages('properties') as $usage) {
            $this->assertFalse($this->termUsages->isUsed($usage));
        }
        foreach ($this->termUsages('resource_classes') as $usage) {
            $this->assertFalse($this->termUsages->isUsed($usage));
        }
    }

    public function testValuesAndResourceClass(): void
    {
        $this->api()->create('items', [
            'o:resource_class' => ['o:id' => $this->termId('resource_classes', 'demo:Book')],
            'demo:author' => [[
                'type' => 'literal',
                'property_id' => $this->termId('properties', 'demo:author'),
                '@value' => 'Demo item',
            ]],
        ]);

        $usages = $this->termUsages('properties');
        $this->assertSame(1, $usages['demo:author']['resources']);
        $this->assertFalse($this->termUsages->isUsed($usages['demo:authorName']));

        $usages = $this->termUsages('resource_classes');
        $this->assertSame(1, $usages['demo:Book']['resources']);
        $this->assertFalse($this->termUsages->isUsed($usages['demo:Map']));
    }

    public function testResourceTemplate(): void
    {
        $this->api()->create('resource_templates', [
            'o:label' => 'Demo template',
            'o:resource_class' => ['o:id' => $this->termId('resource_classes', 'demo:Map')],
            'o:resource_template_property' => [
                ['o:property' => ['o:id' => $this->termId('properties', 'demo:authorName')]],
            ],
        ]);

        $usages = $this->termUsages('properties');
        $this->assertSame(1, $usages['demo:authorName']['templates']);
        $this->assertFalse($this->termUsages->isUsed($usages['demo:author']));

        $usages = $this->termUsages('resource_classes');
        $this->assertSame(1, $usages['demo:Map']['templates']);
        $this->assertFalse($this->termUsages->isUsed($usages['demo:Book']));
    }

    /**
     * A term is found as a whole only, not as the start of another term.
     */
    public function testSettingsMatchWholeTerms(): void
    {
        $this->services()->get('Omeka\Settings')
            ->set('customontology_test', ['fields' => ['item_set/demo:authorName', 'dcterms:title']]);

        $usages = $this->termUsages('properties');
        $this->assertSame(['setting customontology_test'], $usages['demo:authorName']['settings']);
        $this->assertSame([], $usages['demo:author']['settings']);
    }

    /**
     * The ids are found in the queries, encoded or not, and in json.
     */
    public function testSettingsWithIds(): void
    {
        $authorId = $this->termId('properties', 'demo:author');
        $bookId = $this->termId('resource_classes', 'demo:Book');
        $this->services()->get('Omeka\Settings')->set('customontology_test', [
            'query' => 'property%5B0%5D%5Bproperty%5D=' . $authorId . '&property%5B0%5D%5Btype%5D=ex',
            'filters' => ['resource_class_id' => [(string) $bookId]],
        ]);

        $usages = $this->termUsages('properties');
        $this->assertSame(['setting customontology_test'], $usages['demo:author']['settings']);
        $this->assertSame([], $usages['demo:authorName']['settings']);

        $usages = $this->termUsages('resource_classes');
        $this->assertSame(['setting customontology_test'], $usages['demo:Book']['settings']);
        $this->assertSame([], $usages['demo:Map']['settings']);
    }

    /**
     * Facets and filters are set by term, possibly in encoded queries.
     */
    public function testSettingsWithFacetsAndFilters(): void
    {
        $this->services()->get('Omeka\Settings')->set('customontology_test', [
            'url' => '/s/site/search?facet%5Bdemo%3AauthorName%5D%5B%5D=Smith&filter%5B0%5D%5Bfield%5D=demo%3AMap',
        ]);

        $usages = $this->termUsages('properties');
        $this->assertSame(['setting customontology_test'], $usages['demo:authorName']['settings']);
        $this->assertSame([], $usages['demo:author']['settings']);

        $usages = $this->termUsages('resource_classes');
        $this->assertSame(['setting customontology_test'], $usages['demo:Map']['settings']);
        $this->assertSame([], $usages['demo:Book']['settings']);
    }

    /**
     * A setting named for a property may store its id only.
     */
    public function testSettingNamedForPropertyWithId(): void
    {
        $this->services()->get('Omeka\Settings')
            ->set('customontology_test_property', [(string) $this->termId('properties', 'demo:authorName')]);

        $usages = $this->termUsages('properties');
        $this->assertSame(['setting customontology_test_property'], $usages['demo:authorName']['settings']);
        $this->assertSame([], $usages['demo:author']['settings']);
    }

    public function testSites(): void
    {
        $authorId = $this->termId('properties', 'demo:author');
        $authorNameId = $this->termId('properties', 'demo:authorName');
        $mapId = $this->termId('resource_classes', 'demo:Map');
        $site = $this->api()->create('sites', [
            'o:slug' => 'demo-site',
            'o:title' => 'Demo site',
            'o:theme' => 'default',
            'o:item_pool' => ['resource_class_id' => [(string) $mapId]],
            'o:navigation' => [[
                'type' => 'url',
                'data' => ['label' => 'Authors', 'url' => '/s/demo-site/item?property[0][property]=' . $authorId . '&property[0][type]=ex'],
                'links' => [],
            ]],
        ])->getContent();
        $this->api()->create('site_pages', [
            'o:site' => ['o:id' => $site->id()],
            'o:slug' => 'demo-page',
            'o:title' => 'Demo page',
            'o:block' => [[
                'o:layout' => 'browsePreview',
                'o:data' => ['resource_type' => 'items', 'query' => 'property[0][property][]=' . $authorNameId . '&property[0][type]=ex'],
            ]],
        ]);

        $usages = $this->termUsages('properties');
        $this->assertSame(['site demo-site'], $usages['demo:author']['sites']);
        $this->assertCount(1, $usages['demo:authorName']['sites']);
        $this->assertStringStartsWith('site_page_block demo-site/demo-page#', $usages['demo:authorName']['sites'][0]);

        $usages = $this->termUsages('resource_classes');
        $this->assertSame(['site demo-site'], $usages['demo:Map']['sites']);
        $this->assertSame([], $usages['demo:Book']['sites']);
    }

    /**
     * The table of the module Advanced Search is created when missing.
     */
    public function testSearchConfigs(): void
    {
        $connection = $this->services()->get('Omeka\Connection');
        $hasTable = in_array('search_engine', $connection->getSchemaManager()->listTableNames(), true);
        if (!$hasTable) {
            $connection->executeStatement('CREATE TABLE `search_engine` (`id` INT AUTO_INCREMENT NOT NULL, `name` VARCHAR(190) NOT NULL, `settings` LONGTEXT DEFAULT NULL, PRIMARY KEY(`id`))');
        }
        $connection->insert('search_engine', [
            'name' => 'Demo engine test',
            'settings' => json_encode(['fields' => ['demo:author', 'demo:Book']]),
        ]);
        try {
            // A new plugin, because the existing tables are cached.
            $this->termUsages = new \CustomOntology\Mvc\Controller\Plugin\TermUsages($connection);
            $usages = $this->termUsages('properties');
            $this->assertSame(['search_engine Demo engine test'], $usages['demo:author']['search']);
            $this->assertSame([], $usages['demo:authorName']['search']);
            $usages = $this->termUsages('resource_classes');
            $this->assertSame(['search_engine Demo engine test'], $usages['demo:Book']['search']);
        } finally {
            $hasTable
                ? $connection->delete('search_engine', ['name' => 'Demo engine test'])
                : $connection->executeStatement('DROP TABLE `search_engine`');
        }
    }

    /**
     * @return array Usages by term.
     */
    protected function termUsages(string $type): array
    {
        $terms = $this->terms($type);
        $usages = ($this->termUsages)($type, $terms);
        return array_combine($terms, $usages);
    }

    protected function terms(string $type): array
    {
        $terms = [];
        $resourceName = $type === 'properties' ? 'properties' : 'resource_classes';
        foreach ($this->api()->search($resourceName, ['vocabulary_id' => $this->vocabulary->id()])->getContent() as $term) {
            $terms[$term->id()] = $term->term();
        }
        return $terms;
    }

    protected function termId(string $type, string $term): int
    {
        return (int) array_search($term, $this->terms($type), true);
    }
}
