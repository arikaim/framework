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
use Psr\Container\ContainerInterface;

use Arikaim\Core\Framework\Router\RouterInterface;
use Arikaim\Core\Routes\RouteType;
use Arikaim\Core\Http\Url;
use Arikaim\Core\Interfaces\RoutesInterface;
use Arikaim\Core\App\SystemRoutes;
use Arikaim\Core\Access\Middleware\AuthMiddleware;
use Arikaim\Core\Framework\Router\RouteGenerator;

use Exception;

/**
 * App router
 */
class ArikaimRouter implements RouterInterface
{
    /**
     * Route generator
     *
     * @var RouteGenerator
     */
    protected $generator;

    /**
     * App container
     *
     * @var ContainerInterface
     */
    protected $container;

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
     * @param ContainerInterface $container
     * @param string $basePath
     * @param object|null $routeLoader
     */
    public function __construct(ContainerInterface $container, string $basePath, $routeLoader = null)
    {        
        $this->generator = new RouteGenerator(new RouteParser());
        $this->container = $container;
        $this->basePath = $basePath;
        $this->routeMiddlewares = [];
        $this->routeOptions = [];

        $this->routeLoader = ($routeLoader == null) ? $container->get('routes') : $routeLoader;
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
            $route
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
        $routePath = \rtrim(\str_replace(BASE_PATH,'',$path),'/');
        // set current path       
        $type = RouteType::getType($routePath);
    
        switch($type) {
            case RouteType::HOME_PAGE_URL: 
                // home page route                 
                $this->mapRoutes($method,RoutesInterface::HOME_PAGE);
                break;
            case RouteType::ADMIN_PAGE_URL: 
                // add admin twig extension                
                $this->container->get('view')->addExtension(new \Arikaim\Core\App\AdminTwigExtension);
                // map control panel page
                $this->addRoute('GET','/admin[/{language:[a-z]{2}}/]','Arikaim\Core\App\ControlPanel:loadControlPanel');
                // map install page
                $this->addRoute('GET','/admin/install','Arikaim\Core\App\InstallPage:loadInstall');
                break;
            case RouteType::SYSTEM_API_URL: 
                // add admin twig extension
                $this->container->get('view')->addExtension(new \Arikaim\Core\App\AdminTwigExtension);                 
                $this->mapSystemRoutes($method);       
                break;
            case RouteType::API_URL: 
                // api routes only 
                $this->mapRoutes($method,RoutesInterface::API);    
                break;
            case RouteType::ADMIN_API_URL:                
                // add admin twig extension
                $this->container->get('view')->addExtension(new \Arikaim\Core\App\AdminTwigExtension);
                // map admin api routes
                $this->mapRoutes($method,RoutesInterface::API);    
                $this->mapRoutes($method,RoutesInterface::ADMIN_API);    
                break;
            case RouteType::UNKNOW_TYPE:                 
                $this->mapRoutes($method,RoutesInterface::PAGE);
                break;            
        }
    }

    /**
     * Map extensons and templates routes
     *     
     * @param string $method
     * @param int|null $type
     * @return boolean
     * 
     * @throws Exception
     */
    public function mapRoutes(string $method, ?int $type = null): bool
    {      
        try {   
            $routes = ($type == RoutesInterface::HOME_PAGE) ? $this->routeLoader->getHomePageRoute() : $this->routeLoader->searchRoutes($method,$type);                           
        } catch(Exception $e) {
            return false;
        }
       
        foreach($routes as $item) {
            $handler = $item['handler_class'] . ':' . $item['handler_method'];
            $this->addRoute($method,$item['pattern'],$handler,[
                'route_options'        => $item['options'] ?? null,
                'auth'                 => $item['auth'],
                'redirect_url'         => (empty($item['redirect_url']) == false) ? Url::BASE_URL . $item['redirect_url'] : null,
                'route_page_name'      => $item['page_name'] ?? '',
                'route_extension_name' => $item['extension_name'] ?? ''
            ],$item['uuid']);

            // auth middleware
            if (empty($item['auth']) == false) {                              
                $this->addRouteMiddleware($method,$handler,AuthMiddleware::class);              
            } 
    
            $middlewares = (\is_string($item['middlewares']) == true) ? \json_decode($item['middlewares'],true) : $item['middlewares'] ?? [];
            // add middlewares                        
            foreach ($middlewares as $class) {            
               $this->addRouteMiddleware($method,$handler,$class);                               
            }                                                                 
        }    
        
        return true;
    }

    /**
     * Map system routes
     *
     * @param string $method
     * @return void
     */
    protected function mapSystemRoutes(string $method): void
    {       
        if (RouteType::isApiInstallRequest() == true) {         
            $routes = SystemRoutes::$installRoutes[$method] ?? false;
        } else {
            $routes = SystemRoutes::$routes[$method] ?? false;
        }

        if ($routes === false) {
            return;
        }
             
        foreach ($routes as $item) {          
            $this->addRoute($method,$item['pattern'],$item['handler'],[
                'route_options'        => null,
                'auth'                 => $item['auth'] ?? null,
                'redirect_url'         => null,
                'route_page_name'      => '',
                'route_extension_name' => ''
            ]);    
            
            if (empty($item['auth']) == false) {
                $this->addRouteMiddleware($method,$item['handler'],AuthMiddleware::class);                  
            }                                         
        }      
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
