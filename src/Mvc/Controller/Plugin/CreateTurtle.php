<?php declare(strict_types=1);
namespace CustomOntology\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Stdlib\Message;

class CreateTurtle extends AbstractPlugin
{
    /**
     * Create a turtle (simplified Notation3) file from a checked ontology.
     *
     * @param array $ontology Array with an ontology, classes and properties.
     * @return string
     */
    public function __invoke(array $ontology)
    {
        // Initialize the input to simplify process.
        $hasOntology = !empty($ontology['ontology']['o:namespace_uri']);
        $ontology += ['ontology' => [], 'resource_classes' => [], 'properties' => []];

        $prefixes = [];
        if ($hasOntology) {
            $prefixes[$ontology['ontology']['o:prefix']] = $ontology['ontology']['o:namespace_uri'];
        }

        foreach (['resource_classes', 'properties'] as $type) {
            foreach ($ontology[$type] as $element) {
                if (is_string($element['o:vocabulary'])) {
                    if (!$hasOntology) {
                        return new Message($this->getController()->translate('Error: unknown vocabulary for "%1$s" "%2$s".'),
                            $type, $element['o:local_name']
                        );
                    }
                } else {
                    $prefixes[$element['o:vocabulary']->getPrefix()] = $element['o:vocabulary']->getNamespaceUri();
                }
            }
        }

        if (empty($prefixes)) {
            return '';
        }

        $turtle = '';
        if ($hasOntology) {
            $turtle .= <<<'TURTLE'
@prefix dcterms: <http://purl.org/dc/terms/> .
@prefix owl: <http://www.w3.org/2002/07/owl#> .

TURTLE;
        }

        $turtle .= <<<'TURTLE'
@prefix rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#> .
@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .
@prefix vs: <http://www.w3.org/2003/06/sw-vocab-status/ns#> .
@prefix o: <http://omeka.org/s/vocabs/o#> .

TURTLE;

        foreach ($prefixes as $prefix => $namespaceUri) {
            $turtle .= sprintf('@prefix %s: <%s> .', $prefix, $namespaceUri) . PHP_EOL;
        }

        $turtle .= PHP_EOL;

        $formats = [];

        if ($hasOntology) {
            if (empty($ontology['ontology']['o:comment'])) {
                $formats['ontology'] = <<<'TURTLE'
<%1$s> a owl:Ontology ;
    dcterms:title "%2$s" .


TURTLE;
            } else {
                $formats['ontology'] = <<<'TURTLE'
<%1$s> a owl:Ontology ;
    dcterms:title "%2$s" ;
    dcterms:description "%3$s" .


TURTLE;
            }

            $turtle .= vsprintf(
                $formats['ontology'],
                [
                    $ontology['ontology']['o:namespace_uri'],
                    $this->escape($ontology['ontology']['o:label']),
                    empty($ontology['ontology']['o:comment']) ? '' : $ontology['ontology']['o:comment'],
                ]
            );
        }

        $formats['resource_classes'] = <<<'TURTLE'
%1$s:%2$s a rdfs:Class ;
    rdfs:label "%3$s" ;
%4$s    rdfs:domain o:Resource ;
    vs:term_status "experimental" .


TURTLE;

        foreach ($ontology['resource_classes'] as $element) {
            $prefix = is_string($element['o:vocabulary'])
                ? $ontology['ontology']['o:prefix']
                : $element['o:vocabulary']->getPrefix();
            $turtle .= sprintf(
                $formats['resource_classes'],
                $prefix,
                $element['o:local_name'],
                $this->escape($element['o:label']),
                empty($element['o:comment']) ? '' : ('    rdfs:comment "' . $this->escape($element['o:comment']) . '" ;' . PHP_EOL)
            );
        }

        $formats['properties'] = <<<'TURTLE'
%1$s:%2$s a rdf:Property ;
    rdfs:label "%3$s" ;
%4$s    rdfs:domain o:Resource ;
    vs:term_status "experimental" .


TURTLE;

        foreach ($ontology['properties'] as $element) {
            $prefix = is_string($element['o:vocabulary'])
                ? $ontology['ontology']['o:prefix']
                : $element['o:vocabulary']->getPrefix();
            $turtle .= sprintf(
                $formats['properties'],
                $prefix,
                $element['o:local_name'],
                $this->escape($element['o:label']),
                empty($element['o:comment']) ? '' : ('    rdfs:comment "' . $this->escape($element['o:comment']) . '" ;' . PHP_EOL)
            );
        }

        return rtrim($turtle) . PHP_EOL;
    }

    /**
     * Escape double quote and backslash.
     *
     * @link https://www.w3.org/TR/turtle/#sec-escapes
     * @param string $string
     * @return string
     */
    protected function escape($string)
    {
        // No other escape: the source is already checked.
        $replace = [
            '"' => '\\"',
            '\\' => '\\\\',
            "\t" => '\\t',
            "\r" => '\\r',
            "\n" => '\\n',
        ];
        return str_replace(array_keys($replace), array_values($replace), $string);
    }
}
