<?php declare(strict_types=1);

namespace CustomOntology\Service\Controller\Admin;

use CustomOntology\Controller\Admin\CustomOntologyController;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class CustomOntologyControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $entityManager = $services->get('Omeka\EntityManager');
        return new CustomOntologyController(
            $entityManager
        );
    }
}
