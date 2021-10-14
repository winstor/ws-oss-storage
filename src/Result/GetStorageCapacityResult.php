<?php

namespace Winstor\WsOSS\Result;

use Winstor\WsOSS\Core\WsException;

/**
 * Class AclResult  GetBucketAcl interface returns the result class, encapsulated
 * The returned xml data is parsed
 *
 * @package OSS\Result
 */
class GetStorageCapacityResult extends Result
{
    /**
     * Parse data from response
     *
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
        if (isset($xml->StorageCapacity)) {
            return intval($xml->StorageCapacity);
        } else {
            throw new WsException("xml format exception");
        }
    }
}
