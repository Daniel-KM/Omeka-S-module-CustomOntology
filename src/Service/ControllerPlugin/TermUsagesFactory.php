<?php declare(strict_types=1);

namespace CustomOntology\Service\ControllerPlugin;

use CustomOntology\Mvc\Controller\Plugin\TermUsages;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class TermUsagesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new TermUsages(
            $services->get('Omeka\Connection')
        );
    }
}
