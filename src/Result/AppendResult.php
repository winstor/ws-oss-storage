<?php

namespace Winstor\WsOSS\Result;

use Winstor\WsOSS\Core\WsException;

/**
 * Class AppendResult
 * @package OSS\Result
 */
class AppendResult extends Result
{
    /**
     * Get the value of next-append-position from append's response headers
     *
     * @return int
     * @throws WsException
     */
    protected function parseDataFromResponse()
    {
        $header = $this->rawResponse->header;
        if (isset($header["x-oss-next-append-position"])) {
            return intval($header["x-oss-next-append-position"]);
        }
        throw new WsException("cannot get next-append-position");
    }
}
