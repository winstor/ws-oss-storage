<?php
namespace Winstor\WsOSS\Result;

use Winstor\WsOSS\Core\WsException;

/**
 * Class GetLocationResult getBucketLocation interface returns the result class, encapsulated
 * The returned xml data is parsed
 *
 * @package OSS\Result
 */
class GetLocationResult extends Result
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
        return $xml;
    }
}
