<?php declare(strict_types=1);
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
        'invokables' => [
            Controller\NsController::class => Controller\NsController::class,
        ],
        'factories' => [
            Controller\Admin\CustomOntologyController::class => Service\Controller\Admin\CustomOntologyControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'createTurtle' => Mvc\Controller\Plugin\CreateTurtle::class,
            'responseAsFile' => Mvc\Controller\Plugin\ResponseAsFile::class,
        ],
    ],
    'router' => [
        'routes' => [
            'ns' => [
                'type' => 'Literal',
                'options' => [
                    'route' => '/ns',
                    'defaults' => [
                        'controller' => Controller\NsController::class,
                        'action' => 'browse',
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'prefix' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/:prefix',
                            'constraints' => [
                                // Prefix of the namespace uri doesn't allow "-" or "_".
                                'prefix' => '[a-zA-Z][a-zA-Z0-9]*',
                            ],
                            'defaults' => [
                                'action' => 'show',
                            ],
                        ],
                    ],
                ],
            ],
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
                'label' => 'Custom ontology', // @translate
                'route' => 'admin/custom-ontology',
                'resource' => Controller\Admin\CustomOntologyController::class,
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
