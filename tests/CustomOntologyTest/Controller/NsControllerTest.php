<?php declare(strict_types=1);

namespace CustomOntologyTest\Controller;

use CustomOntologyTest\AbstractCustomOntologyTestCase;

class NsControllerTest extends AbstractCustomOntologyTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->createVocabulary(
            'mine',
            $this->managedNamespaceUri('mine'),
            ['Book' => 'Book'],
            ['author' => 'Author']
        );
    }

    public function testBrowseListsOnlyManagedOntologies(): void
    {
        $this->dispatchUnauthenticated('/ns');
        $this->assertResponseStatusCode(200);
        $content = $this->getResponse()->getContent();
        $this->assertStringContainsString($this->managedNamespaceUri('mine'), $content);
        $this->assertStringNotContainsString('purl.org/dc/terms', $content);
    }

    public function testShowIsPublicAndDefaultsToTurtle(): void
    {
        $this->dispatchUnauthenticated('/ns/mine');
        $this->assertResponseStatusCode(200);
        $this->assertResponseHeaderContains('Content-Type', 'text/turtle');

        $turtle = $this->getResponse()->getContent();
        $this->assertStringContainsString('mine:Book a rdfs:Class', $turtle);
        $this->assertStringContainsString('mine:author a rdf:Property', $turtle);
        $graph = new \EasyRdf\Graph();
        $this->assertGreaterThan(0, $graph->parse($turtle, 'turtle'));
    }

    /**
     * @dataProvider formatProvider
     */
    public function testShowFormatByQuery(string $format, string $mediaType, string $needle): void
    {
        $this->dispatchUnauthenticated('/ns/mine?format=' . $format);
        $this->assertResponseStatusCode(200);
        $this->assertResponseHeaderContains('Content-Type', $mediaType);
        $this->assertStringContainsString($needle, $this->getResponse()->getContent());
    }

    public function formatProvider(): array
    {
        return [
            'turtle' => ['turtle', 'text/turtle', 'rdfs:Class'],
            'json-ld' => ['json-ld', 'application/ld+json', '"@id"'],
            'n-triples' => ['n-triples', 'application/n-triples', '<http://www.w3.org/2000/01/rdf-schema#Class>'],
            'rdf-xml' => ['rdf-xml', 'application/rdf+xml', '<rdf:RDF'],
        ];
    }

    public function testShowFormatByAcceptHeader(): void
    {
        // The request is recreated by each dispatch, so the header is added
        // after the reset.
        $this->reset();
        $this->getApplication();
        $this->logout();
        $this->getRequest()->getHeaders()->addHeaderLine('Accept', 'application/ld+json');
        \Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase::dispatch('/ns/mine');
        $this->assertResponseStatusCode(200);
        $this->assertResponseHeaderContains('Content-Type', 'application/ld+json');
        $this->assertIsArray(json_decode($this->getResponse()->getContent(), true));
    }

    public function testShowHtml(): void
    {
        $this->dispatchUnauthenticated('/ns/mine?format=html');
        $this->assertResponseStatusCode(200);
        $this->assertStringContainsString('Author', $this->getResponse()->getContent());
    }

    /**
     * A vocabulary whose namespace is not served by the module is not exposed.
     */
    public function testShowRefusesVocabularyNotManaged(): void
    {
        $this->dispatchUnauthenticated('/ns/dcterms');
        $this->assertResponseStatusCode(404);
    }
}
