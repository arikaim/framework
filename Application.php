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
use Arikaim\Core\Controllers\ErrorController;
use Arikaim\Core\Access\AuthFactory;
use Arikaim\Core\Models\Users;
use Arikaim\Core\Models\AccessTokens;
use PDOException;
use RuntimeException;
use Throwable;
use ErrorException;

/**
 * Application
 */
class Application
{
    /**
     *  Sefault controller class for page not found error
     */
    const DEFAULT_PAGE_NOT_FOUND_HANDLER = ErrorController::class . ':showPageNotFound';

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
     * @var string
     */
    protected $errorHandlerClass;

    /**
     * Constructor
     *
     * @param ContainerInterface $container
     * @param string $errorHandlerClass
     * @param object|null $factory
     */
    public function __construct(
        ContainerInterface $container, 
        RouterInterface $router,
        string $errorHandlerClass, 
        $factory = null
    )
    {        
        $this->container = $container;
        $this->factory = ($factory == null) ? new Psr17Factory() : $factory;      
        $this->router = $router;
        $this->errorHandlerClass = $errorHandlerClass;
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
     * Set error handler class
     *
     * @param string $handlerClass
     * @return void
     */
    public function setErrorHandler(string $handlerClass): void
    {
        \set_error_handler(function($num, $message, $file, $line) {
            throw new ErrorException($message,0,$num,$file,$line);
        });

        $this->errorHandlerClass = $handlerClass;
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
     * @return void
     */
    public function run(?ServerRequestInterface $request = null): void
    {
        // create request
        if ($request == null) {
            $creator = new ServerRequestCreator($this->factory,$this->factory,$this->factory,$this->factory);
            $request = $creator->fromGlobals();
        }
        
        $response = $this->handleRequest($request);

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
     * @throws RuntimeException
     */
    public function handleRequest(ServerRequestInterface $request): ResponseInterface 
    {
        // create empty response
        $response = $this->factory->createResponse(200);

        try {
            // run global middlewares
            foreach($this->middlewares as $item) {
                if (\is_string($item['handler']) == true) {
                    $middleware = new $item['handler']($this->container,$item['options'] ?? []);
                } else {
                    $middleware = $item['handler'];
                }
               
                if ($middleware instanceof MiddlewareInterface) {
                    // process if is valid middleware instance
                    list($request,$response) = $middleware->process($request,$response);     
                }                   
            }

            // dispatch routes
            $uri = $request->getUri()->getPath();
            $method = $request->getMethod();
        
            $this->router->loadRoutes($method,$uri);

            list($status,$route) = $this->router->dispatch($method,$uri);
            if ($status != RouterInterface::ROUTE_FOUND) {
                // route error
                $route['handler'] = Self::DEFAULT_PAGE_NOT_FOUND_HANDLER;
                $this->resolveErrorHandler();
                $response = $this->errorHandler->handleRouteError($response);
            }
           
            // get route options
            $routeOptions = $this->router->getRouteOptions($method,$route['id']);

            // run route middlewares
            $middlewares = $this->router->getRouteMiddlewares($method,$route['handler']);          
            foreach($middlewares as $middlewareClass) {
                $middleware = (\is_string($middlewareClass) == true) ? $this->resolveRouteMiddleware($middlewareClass,$routeOptions) : $middlewareClass;
                               
                if (($middleware instanceof MiddlewareInterface) == false) {
                    throw new RuntimeException('Not valid route middleware ' . $middlewareClass);
                }
        
                list($request,$response) = $middleware->process($request,$response);                 
            }

            // add rouet options
            $request = $request
                ->withAttribute('route',$routeOptions)
                ->withAttribute('current_path',$uri);
                    
            // call route controller
            $response = $this->handleRoute($route,$request,$response);

        } 
        catch (PDOException $exception) {
            $response = $this->handleException($exception,$request,$response);         
        }  
        catch (RuntimeException $exception) {          
            $response = $this->handleException($exception,$request,$response);
        }
        catch (Throwable $exception) {           
            $response = $this->handleException($exception,$request,$response);
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
            AuthFactory::setUserProvider('session',new Users());
            AuthFactory::setUserProvider('token',new AccessTokens());

            $options['authProviders'] = AuthFactory::createAuthProviders($auth,null);
            $options['redirect'] = $options['redirect_url'] ?? null;            
        } 

        return new $middlewareClass($this->container,$options);
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

        return $callable($request,$response,$validator,$route);
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
    private function resolveErrorHandler()
    {
        if ($this->errorHandler == null) {
            $this->errorHandler = new $this->errorHandlerClass($this->container);
        }
    }
}
