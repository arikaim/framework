<?php
/**
 * Arikaim
 *
 * @link        http://www.arikaim.com
 * @copyright   Copyright (c)  Konstantin Atanasov <info@arikaim.com>
 * @license     http://www.arikaim.com/license
 * 
 */
namespace Arikaim\Core\Framework;

use FastRoute\Dispatcher\GroupCountBased;
use FastRoute\RouteCollector;
use FastRoute\DataGenerator\GroupCountBased as GroupCountBasedGenerator;
use FastRoute\RouteParser\Std;
use Psr\Container\ContainerInterface;

use Arikaim\Core\Routes\RouteType;
use Arikaim\Core\Http\Url;
use Arikaim\Core\Interfaces\RoutesInterface;
use Arikaim\Core\App\SystemRoutes;
use Arikaim\Core\Access\Middleware\AuthMiddleware;
use Exception;

/**
 * App router
 */
class Router
{
    /**
     * Route collector
     *
     * @var RouteCollector
     */
    protected $collector;

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
        $this->collector = new RouteCollector(
            new Std(),
            new GroupCountBasedGenerator()
        );     

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
    public function addMiddleware(string $method, string $handlerClass, $middleware): void
    {   
        $this->routeMiddlewares[$method][$handlerClass][] = $middleware;
    } 
    
    /**
     * Get route collector
     *
     * @return RouteCollector
     */
    public function getCollector()
    {
        return $this->collector;
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
        $dispatcher = new GroupCountBased($this->collector->getData());
        $info = $dispatcher->dispatch($method,$uri);
    
        return [      
            'status'  => $info[0] ?? 0,          
            'handler' => $info[1] ?? null,
            'vars'    => $info[2] ?? []
        ];       
    }

    /**
     * Add route
     *
     * @param string $method
     * @param string $pattern
     * @param string $handlerClass
     * @param array $options
     * @return void
     */
    public function addRoute(string $method, string $pattern, string $handlerClass, array $options = []): void
    {      
        $this->collector->addRoute($method,$this->basePath . $pattern,$handlerClass);
        $this->routeOptions[$method][$handlerClass] = $options;
    }

    /**
     * Get reoute options
     *
     * @param string $method
     * @param string $handlerClass
     * @return array
     */
    public function getRouteOptions(string $method, string $handlerClass): array
    {
        return $this->routeOptions[$method][$handlerClass] ?? [];
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
            ]);

            // auth middleware
            if (empty($item['auth']) == false) {                              
                $this->addMiddleware($method,$handler,AuthMiddleware::class);              
            } 
    
            $middlewares = (\is_string($item['middlewares']) == true) ? \json_decode($item['middlewares'],true) : $item['middlewares'] ?? [];
            // add middlewares                        
            foreach ($middlewares as $class) {            
               $this->addMiddleware($method,$handler,$class);                               
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
                $this->addMiddleware($method,$item['handler'],AuthMiddleware::class);                  
            }                                         
        }      
    } 
}
