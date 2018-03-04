<?php
namespace CustomOntology;

use Omeka\Module\AbstractModule;

/**
 * Custom Ontology
 *
 * Create specific classes and properties to describe resources when no standard
 * ontologies can be used.
 *
 * @copyright Daniel Berthereau, 2018
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
