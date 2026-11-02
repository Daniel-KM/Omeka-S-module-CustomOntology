<?php declare(strict_types=1);

namespace CustomOntologyTest\Mvc\Controller\Plugin;

use CustomOntology\Mvc\Controller\Plugin\CreateTurtle;
use Omeka\Entity\Vocabulary;
use PHPUnit\Framework\TestCase;

class CreateTurtleTest extends TestCase
{
    /**
     * @var CreateTurtle
     */
    protected $createTurtle;

    public function setUp(): void
    {
        $this->createTurtle = new CreateTurtle();
    }

    protected function vocabulary(string $prefix, string $namespaceUri): Vocabulary
    {
        $vocabulary = new Vocabulary();
        $vocabulary->setPrefix($prefix);
        $vocabulary->setNamespaceUri($namespaceUri);
        return $vocabulary;
    }

    public function testNewOntologyWithTerms(): void
    {
        $turtle = ($this->createTurtle)([
            'ontology' => [
                'o:namespace_uri' => 'http://example.org/ns/my/',
                'o:prefix' => 'my',
                'o:label' => 'My ontology',
                'o:comment' => 'A description.',
            ],
            'resource_classes' => [
                ['o:vocabulary' => 'my', 'o:local_name' => 'Letter', 'o:label' => 'Letter', 'o:comment' => ''],
            ],
            'properties' => [
                ['o:vocabulary' => 'my', 'o:local_name' => 'sender', 'o:label' => 'Sender', 'o:comment' => 'Who sent it.'],
            ],
        ]);

        $this->assertStringContainsString('@prefix owl: <http://www.w3.org/2002/07/owl#> .', $turtle);
        $this->assertStringContainsString('@prefix my: <http://example.org/ns/my/> .', $turtle);
        $this->assertStringContainsString("<http://example.org/ns/my/> a owl:Ontology ;\n    dcterms:title \"My ontology\" ;\n    dcterms:description \"A description.\" .", $turtle);
        $this->assertStringContainsString('my:Letter a rdfs:Class', $turtle);
        $this->assertStringContainsString('my:sender a rdf:Property', $turtle);
        $this->assertStringContainsString('rdfs:comment "Who sent it."', $turtle);
        // No empty comment.
        $this->assertSame(1, substr_count($turtle, 'rdfs:comment'));
        $this->assertStringEndsWith(".\n", $turtle);

        $graph = new \EasyRdf\Graph();
        $graph->parse($turtle, 'turtle');
        $this->assertSame('Letter', (string) $graph->get('http://example.org/ns/my/Letter', 'rdfs:label'));
        $this->assertSame('rdf:Property', $graph->type('http://example.org/ns/my/sender'));
    }

    public function testOntologyWithoutComment(): void
    {
        $turtle = ($this->createTurtle)([
            'ontology' => [
                'o:namespace_uri' => 'http://example.org/ns/my/',
                'o:prefix' => 'my',
                'o:label' => 'My ontology',
            ],
        ]);
        $this->assertStringContainsString("dcterms:title \"My ontology\" .", $turtle);
        $this->assertStringNotContainsString('dcterms:description', $turtle);
    }

    public function testTermsOfExistingVocabularies(): void
    {
        $turtle = ($this->createTurtle)([
            'properties' => [
                ['o:vocabulary' => $this->vocabulary('aaa', 'http://example.org/aaa#'), 'o:local_name' => 'one', 'o:label' => 'One', 'o:comment' => ''],
                ['o:vocabulary' => $this->vocabulary('bbb', 'http://example.org/bbb/'), 'o:local_name' => 'two', 'o:label' => 'Two', 'o:comment' => ''],
            ],
        ]);
        $this->assertStringContainsString('@prefix aaa: <http://example.org/aaa#> .', $turtle);
        $this->assertStringContainsString('@prefix bbb: <http://example.org/bbb/> .', $turtle);
        $this->assertStringContainsString('aaa:one a rdf:Property', $turtle);
        // No ontology declaration without a new ontology.
        $this->assertStringNotContainsString('owl:Ontology', $turtle);
    }

    public function testEscapesLiterals(): void
    {
        $turtle = ($this->createTurtle)([
            'ontology' => [
                'o:namespace_uri' => 'http://example.org/ns/my/',
                'o:prefix' => 'my',
                'o:label' => 'Label with "quotes" and \\backslash',
            ],
            'properties' => [
                ['o:vocabulary' => 'my', 'o:local_name' => 'p', 'o:label' => "Tab\there", 'o:comment' => "Two\nlines"],
            ],
        ]);
        $this->assertStringContainsString('"Label with \\"quotes\\" and \\\\backslash"', $turtle);
        $this->assertStringContainsString('"Tab\\there"', $turtle);
        $this->assertStringContainsString('"Two\\nlines"', $turtle);

        $graph = new \EasyRdf\Graph();
        $graph->parse($turtle, 'turtle');
        $this->assertSame("Two\nlines", (string) $graph->get('http://example.org/ns/my/p', 'rdfs:comment'));
    }

    public function testNothingToOutput(): void
    {
        $this->assertSame('', ($this->createTurtle)([]));
    }
}
