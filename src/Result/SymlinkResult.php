<?php

namespace Winstor\WsOss\Result;

use Winstor\WsOss\Core\WsException;
use OSS\OssClient;

/**
 *
 * @package OSS\Result
 */
class SymlinkResult extends Result
{
    /**
     * @return string
     * @throws WsException
     */
    protected function parseDataFromResponse()
    {
        $this->rawResponse->header[OssClient::OSS_SYMLINK_TARGET] = rawurldecode($this->rawResponse->header[OssClient::OSS_SYMLINK_TARGET]);
        return $this->rawResponse->header;
    }
}

