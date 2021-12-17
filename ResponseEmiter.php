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

use Psr\Http\Message\ResponseInterface;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitterTrait;

/**
 * Error handler
 */
class ResponseEmiter
{
    use SapiEmitterTrait;

    /**
     * Emit response
     *
     * @param ResponseInterface $response
     * @return boolean
     */
    public function emit(ResponseInterface $response): bool
    {
        if (\headers_sent() === false) {
            $this->emitHeaders($response);
            $this->emitStatusLine($response);
        }
        
        $this->emitBody($response);

        return true;
    }

    /**
     * Emit body.
     */
    private function emitBody(ResponseInterface $response): void
    {
        echo $response->getBody();
    }    
}
