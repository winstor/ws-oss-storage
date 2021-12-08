<?php

namespace Winstor\WsOSS\Result;
/**
 * Class ListObjectsResult
 * @package OSS\Result
 */
class ListObjectsResult extends Result
{
    /**
     * Parse the xml data returned by the ListObjects interface
     *
     * return ObjectListInfo
     */
    protected function parseDataFromResponse()
    {
        return json_decode($this->rawResponse->body,true);
    }
}
