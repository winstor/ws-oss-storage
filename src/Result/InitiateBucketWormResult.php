<?php

namespace Winstor\WsOSS\Result;

use Winstor\WsOSS\Core\WsException;

/**
 * Class InitiateBucketWormResult
 * @package OSS\Result
 */
class InitiateBucketWormResult extends Result
{
    /**
     * Get the value of worm-id from response headers
     *
     * @return int
     * @throws WsException
     */
    protected function parseDataFromResponse()
    {
        $header = $this->rawResponse->header;
        if (isset($header["x-oss-worm-id"])) {
            return strval($header["x-oss-worm-id"]);
        }
        throw new WsException("cannot get worm-id");
    }
}
