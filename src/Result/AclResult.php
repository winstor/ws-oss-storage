<?php

namespace Winstor\WsOSS\Result;

use Winstor\WsOSS\Core\WsException;

/**
 * The type of the return value of getBucketAcl, it wraps the data parsed from xml.
 *
 * @package OSS\Result
 */
class AclResult extends Result
{
    /**
     * @return string
     * @throws WsException
     */
    protected function parseDataFromResponse()
    {
        $content = $this->rawResponse->body;
        if (empty($content)) {
            throw new WsException("body is null");
        }
        $xml = simplexml_load_string($content);
        if (isset($xml->AccessControlList->Grant)) {
            return strval($xml->AccessControlList->Grant);
        } else {
            throw new WsException("xml format exception");
        }
    }
}
