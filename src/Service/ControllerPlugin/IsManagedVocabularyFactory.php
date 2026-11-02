<?php declare(strict_types=1);

namespace CustomOntology\Service\ControllerPlugin;

use CustomOntology\Mvc\Controller\Plugin\IsManagedVocabulary;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class IsManagedVocabularyFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IsManagedVocabulary(
            $services->get('ViewHelperManager')->get('url')
        );
    }
}
