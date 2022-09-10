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

/**
 * Error handler
 */
class ResponseEmiter
{
    /**
     * Emit response
     *
     * @param ResponseInterface $response
     * @return void
     */
    public static function emit(ResponseInterface $response): void
    {
        if (\headers_sent() === false) {
            Self::emitHeaders($response);          
        }
    
        $body = $response->getBody();
    
        // emit body
        $maxLength = 4096;
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $read = (int)$response->getHeaderLine('Content-Length');
        if ($read == false) {
            $read = $body->getSize();
        }

        if ($read == true) {
            while ($read > 0 && $body->eof() == false) {
                $length = \min($maxLength,$read);
                $data = $body->read($length);
                echo $data;

                $read -= strlen($data);
                if (\connection_status() !== CONNECTION_NORMAL) {
                    break;
                }
            }
            return;
        } 

        while ($body->eof() == false) {
            echo $body->read($maxLength);
            if (\connection_status() !== CONNECTION_NORMAL) {
                break;
            }
        }              
    }

    /**
     * Check if respose body is empty
     *
     * @param ResponseInterface $response
     * @param object $body
     * @return boolean
     */
    private static function isEmpty(ResponseInterface $response, $body): bool
    {
        if (\in_array($response->getStatusCode(),[204,205,304],true)) {
            return true;
        }

        if ($body->isSeekable() == true) {
            $body->rewind();
            return ($body->read(1) === '');
        }

        return $body->eof();
    }

    
}
