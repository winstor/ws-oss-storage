<?php

namespace Winstor\WsOSS\Core;


class WsException extends \Exception
{
    private $details = array();

    function __construct($details)
    {
        if (is_array($details)) {
            $message = $details['code'] . ': ' . $details['message']
                . ' RequestId: ' . $details['request-id'];
            parent::__construct($message);
            $this->details = $details;
        } else {
            $message = $details;
            parent::__construct($message);
        }
    }

    public function getHTTPStatus()
    {
        return $this->details['status'] ?? '';
    }

    public function getRequestId()
    {
        return $this->details['request-id'] ?? '';
    }

    public function getErrorCode()
    {
        return $this->details['code'] ?? '';
    }

    public function getErrorMessage()
    {
        return $this->details['message'] ?? '';
    }

    public function getDetails()
    {
        return $this->details['body'] ?? '';
    }

}
