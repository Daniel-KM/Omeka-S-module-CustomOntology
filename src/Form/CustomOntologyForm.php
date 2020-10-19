<?php declare(strict_types=1);
namespace CustomOntology\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;

class CustomOntologyForm extends Form
{
    public function init(): void
    {
        // The action attribute is set via the controller.
        $defaultNs = $this->getOption('default_ns') ?: '';

        $this->add([
            'name' => 'ontology_fieldset',
            'type' => Fieldset::class,
            'options' => [
                'label' => 'Ontology', // @translate
            ],
        ]);
        $ontologyFieldset = $this->get('ontology_fieldset');

        $ontologyFieldset->add([
            'name' => 'o:namespace_uri',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Namespace uri', // @translate
            ],
            'attributes' => [
                'placeholder' => $defaultNs,
            ],
        ]);
        $ontologyFieldset->add([
            'name' => 'o:prefix',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Prefix', // @translate
            ],
            'attributes' => [
                'placeholder' => 'myprefix', // @translate
            ],
        ]);
        $ontologyFieldset->add([
            'name' => 'o:label',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Label', // @translate
            ],
            'attributes' => [
                'placeholder' => 'My label', // @translate
            ],
        ]);
        $ontologyFieldset->add([
            'name' => 'o:comment',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Comment', // @translate
            ],
            'attributes' => [
                'placeholder' => 'A specific ontology for my documents.', // @translate
            ],
        ]);

        $this->add([
            'name' => 'resource_classes_fieldset',
            'type' => Fieldset::class,
            'options' => [
                'label' => 'Classes', // @translate
            ],
        ]);
        $resourceClassesFieldset = $this->get('resource_classes_fieldset');

        $resourceClassesFieldset->add([
            'name' => 'resource_classes',
            'type' => Element\Textarea::class,
            'options' => [
                'label' => ' ',
            ],
            'attributes' => [
                'placeholder' => 'myprefix:MySpecificClass, My specific class, A resource class to use for my specific documents.', // @translate
                'rows' => 10,
            ],
        ]);

        $this->add([
            'name' => 'properties_fieldset',
            'type' => Fieldset::class,
            'options' => [
                'label' => 'Properties', // @translate
            ],
        ]);
        $propertiesFieldset = $this->get('properties_fieldset');

        $propertiesFieldset->add([
            'name' => 'properties',
            'type' => Element\Textarea::class,
            'options' => [
                'label' => ' ',
            ],
            'attributes' => [
                'placeholder' => 'myprefix:mySpecificProperty, My specific property, A property to use for my specific documents.', // @translate
                'rows' => 10,
            ],
        ]);

        $inputFilter = $this->getInputFilter();
        foreach ($this as $element) {
            $inputFilter->add([
                'name' => $element->getName(),
                'required' => false,
            ]);
        }
    }
}
