<?php
namespace CustomOntology\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * Prepare a response for a file.
 */
class ResponseAsFile extends AbstractPlugin
{
    /**
     * Output a string as file in a response.
     *
     * @param string $text
     * @param string $filename
     * @param string $mediaType
     * @param string $mode "inline" or "attachment" (default).
     * @param string $cacheControl "public", "private", "no-cache", "no-store"…
     * @param array $specificHeaders Full specific headers.
     * @return \Laminas\Stdlib\ResponseInterface
     */
    public function __invoke(
        $text,
        $filename = 'output.txt',
        $mediaType = 'text/plain',
        $mode = 'attachment',
        $cacheControl = 'public',
        array $specificHeaders = []
    ) {
        $fileSize = mb_strlen($text);

        /** @var \Laminas\Http\Response $response */
        $controller = $this->getController();
        $response = $controller->getResponse();

        // Write HTTP headers
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-type: ' . $mediaType);
        $headers->addHeaderLine('Content-Disposition: ' . $mode . '; filename="' . $filename . '"');
        $headers->addHeaderLine('Content-Transfer-Encoding', 'binary');
        $headers->addHeaderLine('Content-length: ' . $fileSize);
        $headers->addHeaderLine('Cache-control: ' . $cacheControl);
        $headers->addHeaderLine('Content-Description: ' . 'File Transfer');

        foreach ($specificHeaders as $header) {
            $headers->addHeaderLine($header);
        }

        // Write file content.
        $response->setContent($text);

        // Return Response to avoid default view rendering
        return $response;
    }
}
