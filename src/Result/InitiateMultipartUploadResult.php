<?php

namespace Winstor\WsOSS\Result;

use Winstor\WsOSS\Core\WsException;


/**
 * Class initiateMultipartUploadResult
 * @package OSS\Result
 */
class InitiateMultipartUploadResult extends Result
{
    /**
     * Get uploadId in result and return
     *
     * @throws WsException
     * @return string
     */
    protected function parseDataFromResponse()
    {
        $content = $this->rawResponse->body;
        $xml = simplexml_load_string($content);
        if (isset($xml->UploadId)) {
            return strval($xml->UploadId);
        }
        throw new WsException("cannot get UploadId");
    }
}
