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
use Psr\Container\ContainerInterface;

use Arikaim\Core\Framework\MiddlewareInterface;

/**
 *  Middleware base class
 */
abstract class Middleware implements MiddlewareInterface
{
    /**
     * Middleware params
     *
     * @var array
     */
    protected $params = [];

    /**
     * Container
     *
     * @var ContainerInterface|null
     */
    protected $container = null;

    /**
     * Process middleware 
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return array [$request,$response]
     */
    abstract public function process(ServerRequestInterface $request, ResponseInterface $response): array; 

    /**
     * Constructor
     *
     * @param array|null $params
     */
    public function __construct($container = null, ?array $params = [])
    {
        $this->container = $container;
        $this->params = $params ?? [];
    }
    
    /**
     * Get param value
     *
     * @param string $name
     * @param mixed $default
     * @return mixed|null
     */
    public function getParam(string $name, $default = null)
    {
        return $this->params[$name] ?? $default;
    }

    /**
     * Set param
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function setParam(string $name, $value): void
    {
        $this->params[$name] = $value;        
    }

    /**
     * Set param
     *
     * @param string $name
     * @param mixed $value
     * @return Middleware
     */
    public function withParam(string $name, $value)
    {
        $this->setParam($name,$value);
        
        return $this;
    }

    /**
     * Return all params
     *
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
