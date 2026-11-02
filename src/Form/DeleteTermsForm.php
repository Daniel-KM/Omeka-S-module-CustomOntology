<?php declare(strict_types=1);

namespace CustomOntology\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * Select the unused terms of a vocabulary to delete.
 *
 * The options are set by the controller with the unused terms only, and the
 * controller checks the posted terms again before the deletion.
 */
class DeleteTermsForm extends Form
{
    public function init(): void
    {
        $this
            ->setAttribute('id', 'delete-terms-form')
            ->add([
                'name' => 'resource_classes',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Classes', // @translate
                    'value_options' => [],
                ],
            ])
            ->add([
                'name' => 'properties',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Properties', // @translate
                    'value_options' => [],
                ],
            ]);

        // One of the two lists may be empty.
        $inputFilter = $this->getInputFilter();
        foreach (['resource_classes', 'properties'] as $name) {
            $inputFilter->add([
                'name' => $name,
                'required' => false,
            ]);
        }
    }
}
