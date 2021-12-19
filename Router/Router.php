<?php
/**
 * Arikaim
 *
 * @link        http://www.arikaim.com
 * @copyright   Copyright (c)  Konstantin Atanasov <info@arikaim.com>
 * @license     http://www.arikaim.com/license
 * 
 */
namespace Arikaim\Core\Framework\Router;

use FastRoute\RouteParser\Std as RouteParser;

use Arikaim\Core\Framework\Router\RouterInterface;
use Arikaim\Core\Framework\Router\RouteGenerator;

/**
 * App router
 */
class Router implements RouterInterface
{
    /**
     * Route generator
     *
     * @var RouteGenerator
     */
    protected $generator;

    /**
     * Route loader
     *
     * @var null|object
     */
    protected $routeLoader;

    /**
     * Route middlewares
     *
     * @var array
     */
    protected $routeMiddlewares = [];

    /**
     * Route options
     *
     * @var array
     */
    protected $routeOptions = [];

    /**
     * Constructor
     *  
     * @param string $basePath    
     */
    public function __construct(string $basePath)
    {        
        $this->generator = new RouteGenerator(new RouteParser());    
        $this->basePath = $basePath;
        $this->routeMiddlewares = [];
        $this->routeOptions = [];        
    }

    /**
     * Get route middlewares
     *
     * @param string $method
     * @param string $handlerClass
     * @return array
     */
    public function getRouteMiddlewares(string $method, string $handlerClass): array
    {
        return $this->routeMiddlewares[$method][$handlerClass] ?? [];
    }

    /**
     * Add route middleware
     *
     * @param string $method
     * @param string $handlerClass
     * @param string|object $middleware
     * @return void
     */
    public function addRouteMiddleware(string $method, string $handlerClass, $middleware): void
    {   
        $this->routeMiddlewares[$method][$handlerClass][] = $middleware;
    } 
    
    /**
     * Get route generator
     *
     * @return RouteGenerator
     */
    public function getGenerator()
    {
        return $this->generator;
    }

    /**
     * Dispatch route
     *
     * @param string $method
     * @param string $uri
     * @return array
     */
    public function dispatch(string $method, string $uri): array
    {
        list($staticRoutes,$variableRoutes) = $this->generator->getData();

        if (isset($staticRoutes[$method][$uri]) == true) {
            return [RouterInterface::ROUTE_FOUND,$staticRoutes[$method][$uri]];             
        }
      
        if (isset($variableRoutes[$method]) == true) {
            $route = $this->dispatchVariableRoute($variableRoutes[$method],$uri);            
        }

        return [
            (($route ?? null) == null) ? RouterInterface::ROUTE_NOT_FOUND : RouterInterface::ROUTE_FOUND,
            $route ?? [
                'id'        => null,
                'methhod'   => $method,
                'handler'   => null,
                'regex'     => null,
                'variables' => []
            ]  
        ];  
    }

    /**
     * Add route
     *
     * @param string $method
     * @param string $pattern
     * @param string $handlerClass
     * @param array $options
     * @param string|int|null $routeId
     * @return void
     */
    public function addRoute(string $method, string $pattern, string $handlerClass, array $options = [], $routeId = null): void
    {      
        $this->generator->addRoute($method,$this->basePath . $pattern,$handlerClass,$routeId);
        if (empty($routeId) == false) {
            $this->routeOptions[$method][$routeId] = $options;
        }
    }

    /**
     * Get reoute options
     *
     * @param string $method
     * @param string|int $id
     * @return array
     */
    public function getRouteOptions(string $method, $id): array
    {
        return (empty($id) == true) ? [] : $this->routeOptions[$method][$id] ?? [];
    }
    
    /**
     * Load routes
     *
     * @param string $method
     * @param string $path
     * @return void
     */
    public function loadRoutes(string $method, string $path): void
    {       
    }

    /**
     * Dispatch variable route
     *
     * @param array $routes
     * @param string $uri
     * @return array|null
     */
    protected function dispatchVariableRoute(array $routes, string $uri): ?array
    {
        foreach ($routes as $data) {
            if (\preg_match($data['regex'],$uri,$matches) == false) {
                continue;
            }

            $route = $data['routeMap'][\count($matches)];
            $vars = [];
            $index = 0;

            foreach ($route['variables'] as $varName) {
                $vars[$varName] = $matches[++$index];
            }
            $route['variables'] = $vars;

            return $route;
        }

        return null;
    }
}
