<?php
/**
 * Arikaim
 *
 * @link        http://www.arikaim.com
 * @copyright   Copyright (c)  Konstantin Atanasov <info@arikaim.com>
 * @license     http://www.arikaim.com/license
 * 
*/
namespace Arikaim\Core\Framework\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use Arikaim\Core\Framework\MiddlewareInterface;
use RuntimeException;

/**
 * Request body parsing
 */
class BodyParsingMiddleware implements MiddlewareInterface
{
    /**
     * @var callable[]
     */
    protected $bodyParsers;

    /**
     * Constructor 
     * 
     */
    public function __construct()
    {
        $this->registerDefaultBodyParsers();        
    }

    /**
     * Process middleware 
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return array [$request,$response]
     */
    public function process(ServerRequestInterface $request, ResponseInterface $response): array
    {    
        if (empty($request->getParsedBody()) == true) {
            $parsedBody = $this->parseBody($request);
            $request = $request->withParsedBody($parsedBody);
        }

        // Add Content-Length header if not already added
        $size = $response->getBody()->getSize();
        if ($size !== null && !$response->hasHeader('Content-Length')) {
            $response = $response->withHeader('Content-Length',(string)$size);
        }

        return [$request,$response];
    }

    /**
     * Register parser 
     * 
     * @param string   $mediaType 
     * @param callable $callable  
     * @return self
     */
    public function registerBodyParser(string $mediaType, callable $callable): self
    {
        $this->bodyParsers[$mediaType] = $callable;

        return $this;
    }

    /**
     * Return true if parser exist
     * @param string   $mediaType A HTTP media type (excluding content-type params).
     * @return boolean
     */
    public function hasBodyParser(string $mediaType): bool
    {
        return isset($this->bodyParsers[$mediaType]);
    }

    /**
     * Get parser
     * 
     * @param string    $mediaType A HTTP media type (excluding content-type params).
     * @return callable
     * @throws RuntimeException
     */
    public function getBodyParser(string $mediaType): callable
    {
        if (isset($this->bodyParsers[$mediaType]) == false) {
            throw new RuntimeException('No parser for type ' . $mediaType);
        }

        return $this->bodyParsers[$mediaType];
    }

    /**
     * Register default parsers
     *
     * @return void
     */
    protected function registerDefaultBodyParsers(): void
    {
        // json      
        $this->registerBodyParser('application/json',function($input) {
            $result = \json_decode($input,true);
            return (\is_array($result) == false) ? null : $result;         
        });

        // form
        $this->registerBodyParser('application/x-www-form-urlencoded',function($input) {
            \parse_str($input,$data);
            return $data;
        });

        // xml
        $this->registerBodyParser('application/xml',function($input) {          
            $result = \simplexml_load_string($input);
            \libxml_clear_errors();
            \libxml_use_internal_errors(true);

            return ($result === false) ? null : $result;             
        });       
    }

    /**
     * Parse body
     * 
     * @param ServerRequestInterface $request
     * @return null|array|object
     */
    protected function parseBody(ServerRequestInterface $request)
    {
        $mediaType = $this->getMediaType($request);
        if ($mediaType === null) {
            return null;
        }

        // Check if this specific media type has a parser registered first
        if (isset($this->bodyParsers[$mediaType]) == false) {
            $parts = \explode('+', $mediaType);
            if (count($parts) >= 2) {
                $mediaType = 'application/' . $parts[count($parts) - 1];
            }
        }

        if (isset($this->bodyParsers[$mediaType]) == true) {
            $body = (string)$request->getBody();
            $parsed = $this->bodyParsers[$mediaType]($body);

            if (\is_null($parsed) == false && \is_object($parsed) == false && \is_array($parsed) == false) {
                throw new RuntimeException(
                    'Request body media type parser return value must be an array, an object, or null'
                );
            }

            return $parsed;
        }

        return null;
    }

    /**
     * @param ServerRequestInterface $request
     * @return string|null 
     */
    protected function getMediaType(ServerRequestInterface $request): ?string
    {
        $contentType = $request->getHeader('Content-Type')[0] ?? null;

        if (\is_string($contentType) == true && \trim($contentType) != '') {
            $contentTypeParts = \explode(';', $contentType);
            return \strtolower(\trim($contentTypeParts[0]));
        }

        return null;
    }
}
