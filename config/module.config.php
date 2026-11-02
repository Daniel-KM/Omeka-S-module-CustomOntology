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
            Form\DeleteTermsForm::class => Form\DeleteTermsForm::class,
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
        'factories' => [
            'isManagedVocabulary' => Service\ControllerPlugin\IsManagedVocabularyFactory::class,
            'termUsages' => Service\ControllerPlugin\TermUsagesFactory::class,
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
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/custom-ontology[/:action[/:id]]',
                            'constraints' => [
                                'action' => 'vocabulary',
                                'id' => '\d+',
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
        /*// Not possible because keys are numeric in navigation.conf.
        'AdminResource' => [
            'vocabularies' => [
                'pages' => [
                    [
                        'route' => 'admin/custom-ontology',
                        'controller' => Controller\Admin\CustomOntologyController::class,
                        'resource' => Controller\Admin\CustomOntologyController::class,
                        'privilege' => 'create',
                        'visible' => false,
                    ],
                ],
            ],
        ],
        */
        'AdminModule' => [
            'custom_ontology' => [
                'label' => 'Custom ontology', // @translate
                'route' => 'admin/custom-ontology',
                'class' => 'vocabularies',
                // Only users with right to import a vocabulary can create one.
                'resource' => \Omeka\Api\Adapter\VocabularyAdapter::class,
                'privilege' => 'create',
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
