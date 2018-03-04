<?php
namespace CustomOntology;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\CustomOntologyForm::class => Form\CustomOntologyForm::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\Admin\CustomOntologyController::class => Service\Controller\Admin\CustomOntologyControllerFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'custom-ontology' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/custom-ontology[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'CustomOntology\Controller\Admin',
                                'controller' => Controller\Admin\CustomOntologyController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Custom ontologies', // @translate
                'route' => 'admin/custom-ontology',
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
];
