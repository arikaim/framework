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

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7Server\ServerRequestCreator;
use Nyholm\Psr7\Factory\Psr17Factory;

use Arikaim\Core\Framework\ResponseEmiter;
use Arikaim\Core\Framework\Router\RouterInterface;
use Arikaim\Core\Framework\MiddlewareInterface;
use Arikaim\Core\Validator\Validator;
use Arikaim\Core\Access\AuthFactory;
use Throwable;

/**
 * Application
 */
class Application
{
    /**
     *  Sefault controller class for page not found error
     */
    const DEFAULT_PAGE_NOT_FOUND_HANDLER = '\Arikaim\Core\Controllers\ErrorController:showPageNotFound';
    /**
     *  Default error handler class
     */
    const DEFUALT_ERROR_HANDLER = '\Arikaim\Core\Framework\ErrorHandler';

    /**
     * Global middlewares
     *
     * @var array
     */
    protected $middlewares = [];

    /**
     * App container
     *
     * @var ContainerInterface
     */
    protected $container;

    /**
     * Psr17 factory
     *
     * @var object
     */
    protected $factory;

    /**
     * Router
     *
     * @var RouterInterface
     */
    protected $router;

    /**
     * Error handler
     *
     * @var object|null
     */
    protected $errorHandler = null;

    /**
     * Error handler class
     *
     * @var string|null
     */
    protected $errorHandlerClass;

    /**
     * Constructor
     *
     * @param ContainerInterface $container
     * @param string|null $errorHandlerClass
     * @param object|null $factory
     */
    public function __construct(
        ContainerInterface $container, 
        RouterInterface $router,
        ?string $errorHandlerClass = null, 
        $factory = null
    )
    {        
        $this->container = $container;
        $this->factory = ($factory == null) ? new Psr17Factory() : $factory;      
        $this->router = $router;
        $this->errorHandlerClass = $errorHandlerClass;
    }

    /**
     * Swt router
     *
     * @param RouterInterface $router
     * @return void
     */
    public function setRouter(RouterInterface $router): void
    {
        $this->router = $router;
    }

    /**
     * Get router
     *
     * @return RouterInterface
     */
    public function getRouter(): RouterInterface
    {
        return $this->router;
    }

    /**
     * Return psr17 factory
     *
     * @return object
     */
    public function getFactory()
    {
        return $this->factory;
    }

    /**
     * Get container
     *
     * @return ContainerInterface
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
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
        $this->router->addRoute($method,$pattern,$handlerClass,$options,$routeId);
    }

    /**
     * Create response
     *
     * @param integer $status
     * @return ResponseInterface
     */
    public function createResponse(int $status = 200): ResponseInterface
    {
        return $this->factory->createResponse($status);
    }

    /**
     * Add global middleware
     *
     * @param object|string $middleware
     * @param array $options
     * @return void
     */
    public function addMiddleware($middleware, array $options = []): void
    {   
        $this->middlewares[] = [
            'handler' => $middleware,
            'options' => $options
        ];
    } 

    /**
     * Add route middleware
     *
     * @param string $httpMethod
     * @param string $routeHandlerClass
     * @param string|object $middleware
     * @return void
     */
    public function addRouteMiddleware(string $method, string $routeHandlerClass, $middleware)
    {      
        $this->router->addRouteMiddleware($method,$routeHandlerClass,$middleware);
    } 

    /**
     * Run application
     *
     * @param ServerRequestInterface|null $request
     * @param array $options
     * @return void
     */
    public function run(?ServerRequestInterface $request = null, ?array $options = []): void
    {
        // create request
        if ($request == null) {
            $creator = new ServerRequestCreator($this->factory,$this->factory,$this->factory,$this->factory);
            $request = $creator->fromGlobals();
        }
     
        // load routes
        $this->router->loadRoutes(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $options['adminPagePath'] ?? null
        );

        // handle
        $response = $this->handleRequest($request,$this->factory->createResponse(200));

        try {
            // emit        
            ResponseEmiter::emit($response);
        } catch (Throwable $exception) {           
            $response = $this->handleException($exception,$request,$response);
            ResponseEmiter::emit($response);
        }
    }

    /**
     * Handle http request
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface   
     */
    public function handleRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface 
    {
        try {    
            // run middlewares
            foreach ($this->middlewares as $item) {
                $handler = $item['handler'] ?? '';
                if (empty($handler) == true) {
                    continue;
                }
                $middleware = new $handler($this->container,$item['options'] ?? []);

                if ($middleware instanceof MiddlewareInterface) {
                    // process if is valid middleware instance
                    list($request,$response) = $middleware->process($request,$response);     
                }                   
            }

            // dispatch routes
            $uri = $request->getUri()->getPath();
            $method = $request->getMethod();
        
            list($status,$route) = $this->router->dispatch($method,$uri);
          
            if ($status != RouterInterface::ROUTE_FOUND) {
                // route error
                $route['handler'] = Self::DEFAULT_PAGE_NOT_FOUND_HANDLER;
                $this->resolveErrorHandler();
            
                if (\Arikaim\Core\App\Install::isInstalled() == false) {               
                    if  (
                        \Arikaim\Core\Routes\RouteType::isInstallPage() == false && 
                        \Arikaim\Core\Routes\RouteType::isApiInstallRequest() == false
                        ) { 
                        // redirect to install page                   
                        return $this->errorHandler->redirectToInstallPage($response);                  
                    }                       
                }               
            }
           
            // get route options
            $routeOptions = $this->router->getRouteOptions($method,$route['id']);

            // run route middlewares
            $middlewares = $this->router->getRouteMiddlewares($method,$route['handler']);          
            foreach ($middlewares as $middlewareClass) {
                $middleware = (\is_string($middlewareClass) == true) ? $this->resolveRouteMiddleware($middlewareClass,$routeOptions) : $middlewareClass;
                               
                if ($middleware instanceof MiddlewareInterface) {
                    list($request,$response) = $middleware->process($request,$response);       
                }                
            }

            // add route options
            $request = $request->withAttribute('route',$routeOptions);
                    
            // call route controller
            $response = $this->handleRoute($route,$request,$response);
        } 
        catch (Throwable $exception) {           
            $response = $this->handleException($exception,$request,$response);
            if (\Arikaim\Core\Routes\RouteType::isInstallPage() == true) { 
                $response = $this->handleRoute($route,$request,$response);
            } 
        }

        return $response;
    }

    /**
     * Create middleware instance
     *
     * @param string $middlewareClass
     * @param array $options
     * @return MiddlewareInterface|null  
     */
    protected function resolveRouteMiddleware(string $middlewareClass, array $options): ?MiddlewareInterface
    {
        $auth = $options['auth'] ?? null;
      
        if (empty($auth) == false) {
            // auth middleware
            AuthFactory::setUserProvider('session',new \Arikaim\Core\Models\Users());
            AuthFactory::setUserProvider('token',new \Arikaim\Core\Models\AccessTokens());
            $options['authProviders'] = AuthFactory::createAuthProviders($auth,null);              
        } 

        return (\class_exists($middlewareClass) == true) ? new $middlewareClass($this->container,$options) : null;
    }

    /**
     * Render app exception
     *
     * @param Throwable $exception
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    protected function handleException(
        Throwable $exception, 
        ServerRequestInterface $request, 
        ResponseInterface $response
    ): ResponseInterface
    {
        $this->resolveErrorHandler();
         
        return $this->errorHandler->renderExecption($exception,$request,$response);
    }

    /**
     * Execute route handler
     *
     * @param array $route
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    protected function handleRoute(array $route, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {            
        $body = $request->getParsedBody();        
        $data = \array_merge($route['variables'],(\is_array($body) == false) ? [] : $body);
        
        $callable = $this->resolveCallable($route['handler'],$response);
       
        $validator = new Validator(
            $data,
            function() use ($callable) {
                return $callable[0]->getDataValidCallback();
            },
            function() use($callable) {
                return $callable[0]->getValidationErrorCallback();
            }
        );

        $response = $callable($request,$response,$validator);

        return ($response instanceof ResponseInterface) ? $response : $callable[0]->getResponse();
    }

    /**
     * Resolve route handler
     *
     * @param string $callable
     * @param ResponseInterface $response
     * @return array
     */
    public function resolveCallable(string $callable, ResponseInterface $response): array
    {
        $parts = \explode(':',$callable);      
        $instance = new $parts[0]($this->container);
        $instance->setHttpResponse($response);

        return [$instance,$parts[1] ?? '__invoke'];
    }

    /**
     * Create error handler if not set
     *
     * @return void
     */
    private function resolveErrorHandler(): void
    {
        if ($this->errorHandler == null) {
            $this->errorHandlerClass = $this->errorHandlerClass ?? Self::DEFUALT_ERROR_HANDLER;
            $this->errorHandler = new $this->errorHandlerClass($this->container);
        }
    }
}
