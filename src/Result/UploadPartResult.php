<?php

namespace Winstor\WsOSS\Result;

use Winstor\WsOSS\Core\WsException;

/**
 * Class UploadPartResult
 * @package OSS\Result
 */
class UploadPartResult extends Result
{
    /**
     * 结果中part的ETag
     *
     * @return string
     * @throws WsException
     */
    protected function parseDataFromResponse()
    {
        $header = $this->rawResponse->header;
        if (isset($header["etag"])) {
            return $header["etag"];
        }
        throw new WsException("cannot get ETag");

    }
}
