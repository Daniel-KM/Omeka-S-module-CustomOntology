<?php declare(strict_types=1);

namespace CustomOntology\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\View\Helper\Url;

/**
 * Check if a vocabulary is managed by the module.
 *
 * A vocabulary is managed when its namespace uri is the one served by the
 * module (route "ns/prefix"), on the current domain or on localhost.
 */
class IsManagedVocabulary extends AbstractPlugin
{
    /**
     * @var Url
     */
    protected $url;

    public function __construct(Url $url)
    {
        $this->url = $url;
    }

    public function __invoke(string $prefix, string $namespaceUri): bool
    {
        $url = $this->url;
        $ns = $url('ns/prefix', ['prefix' => $prefix], ['force_canonical' => true]);
        // Check localhost to simplify local usage.
        $nsr = '://localhost' . $url('ns/prefix', ['prefix' => $prefix]);
        return strpos($namespaceUri, $ns) === 0
            || strpos($namespaceUri, 'http' . $nsr) === 0
            || strpos($namespaceUri, 'https' . $nsr) === 0;
    }
}
