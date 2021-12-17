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
use Arikaim\Core\System\Error\Renderer\HtmlPageErrorRenderer;
use Arikaim\Core\System\Error\ApplicationError;
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
     * @var object|null
     */
    protected $renderer = null;

    /**
     * Constructor
     *
     * @param ContainerInterface|null $container
     * @param object|null $renderer
     */
    public function __construct(?ContainerInterface $container = null, $renderer = null)
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
                return $response
                    ->withoutHeader('Cache-Control')
                    ->withHeader('Cache-Control','no-cache, must-revalidate')
                    ->withHeader('Content-Length','0')    
                    ->withHeader('Expires','Sat, 26 Jul 1997 05:00:00 GMT')        
                    ->withHeader('Location',RouteType::getInstallPageUrl())
                    ->withStatus(307);                  
            } 
                
            return $response;
        }
    
        $this->resolveRenderer();

        // render errror
        $renderType = (Request::isJsonContentType($request) == true) ? 'json' : 'html';
       
        $output = $this->renderer->renderError($exception,$renderType);
        $response->getBody()->write($output);

        return $response->withStatus($status);      
    }

    /**
     * Create renderer if not set
     *
     * @return void
     */
    private function resolveRenderer(): void
    {
        if ($this->renderer == null) {
            $this->renderer = new ApplicationError(
                new HtmlPageErrorRenderer($this->container->get('page'))
            );  
        }
    } 
}
