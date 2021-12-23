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

use Arikaim\Core\Http\Request;
use Arikaim\Core\App\Install;
use Arikaim\Core\Routes\RouteType;
use Arikaim\Core\System\Error\ApplicationError;
use Arikaim\Core\System\Error\ErrorHandlerInterface;
use Arikaim\Core\Access\AccessDeniedException;
use ErrorException;
use Throwable;

/**
 * Error handler
 */
class ErrorHandler
{
    /**
     * Container
     *
     * @var ContainerInterface|null
     */
    protected $container = null;

    /**
     * Error renderer
     *
     * @var ErrorHandlerInterface|null
     */
    protected $renderer = null;

    /**
     * Constructor
     *
     * @param ContainerInterface|null $container
     * @param object|null $renderer
     */
    public function __construct(?ContainerInterface $container = null, ?ErrorHandlerInterface $renderer = null)
    {        
        $this->container = $container;    
        $this->renderer = $renderer;            
    }

    /**
     * Handle php app errors
     *
     * @param mixed $num
     * @param mixed $message
     * @param mixed $file
     * @param mixed $line
     * @return void
     */
    public function handleError($num, $message, $file, $line)
    {
        throw new ErrorException($message,0,$num,$file,$line);
    }

    /**
     * Handle route error
     *
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function handleRouteError(ResponseInterface $response): ResponseInterface
    {
        if (Install::isInstalled() == false && RouteType::isInstallPage() == false) {
            // redirect to install page                                
            return $this->redirectToInstallPage($response); 
        }

        return $response;
    } 

    /**
     * Render exception
     *
     * @param Throwable $exception
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function renderExecption(Throwable $exception, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {       
        $status = 400;    
      
        if (Install::isInstalled() == false) {    
            if (RouteType::isApiInstallRequest() == true) {
                return $response;
            }
            if (RouteType::isInstallPage() == false) { 
                // redirect to install page                                
                return $this->redirectToInstallPage($response);                  
            }                 
            return $response;
        }
    
        $this->resolveRenderer();

        if ($exception instanceof AccessDeniedException) {
            $response = ($exception->getResponse() != null) ? $exception->getResponse() : $response;              
        }
        // render errror
        $renderType = (Request::isJsonContentType($request) == true) ? 'json' : 'html';
       
        $output = $this->renderer->renderError($exception,$renderType);
        $response->getBody()->write($output);

        return $response->withStatus($status);      
    }

    /**
     * Redirect to install page
     *
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    protected function redirectToInstallPage(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withoutHeader('Cache-Control')
            ->withHeader('Cache-Control','no-cache, must-revalidate')
            ->withHeader('Content-Length','0')    
            ->withHeader('Expires','Sat, 26 Jul 1997 05:00:00 GMT')        
            ->withHeader('Location',RouteType::getInstallPageUrl())
            ->withStatus(307);   
    }

    /**
     * Create renderer if not set
     *
     * @return void
     */
    private function resolveRenderer(): void
    {
        if ($this->renderer == null) {
            $this->renderer = new ApplicationError($this->container->get('page'));              
        }
    } 
}
