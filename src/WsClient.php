<?php


namespace Winstor\WsOSS;


use Winstor\WsOSS\Core\MimeTypes;
use Winstor\WsOSS\Core\OssUtil;
use Winstor\WsOSS\Core\WsException;
use Winstor\WsOSS\Http\RequestCore;
use Winstor\WsOSS\Http\RequestCore_Exception;
use Winstor\WsOSS\Http\ResponseCore;
use Winstor\WsOSS\Result\BodyResult;
use Winstor\WsOSS\Result\CallbackResult;
use Winstor\WsOSS\Result\ExistResult;
use Winstor\WsOSS\Result\HeaderResult;
use Winstor\WsOSS\Result\ListObjectsResult;
use Winstor\WsOSS\Result\PutSetDeleteResult;

class WsClient
{

    /**
     * @var string
     */
    protected $key;
    /**
     * @var string
     */
    protected $secret;
    /**
     * @var string
     */
    protected $endpoint;
    private $maxRetries = 3;
    private $redirects = 0;

    /**
     * WsClient constructor.
     * @param string $key
     * @param string $secret
     * @param string $endpoint
     * @throws WsException
     */
    public function __construct(string $key, string $secret, string $endpoint)
    {
        $key = trim($key);
        $secret = trim($secret);
        $endpoint = trim(trim($endpoint), "/");

        if (empty($key)) {
            throw new WsException("access key id is empty");
        }
        if (empty($secret)) {
            throw new WsException("access key secret is empty");
        }
        if (empty($endpoint)) {
            throw new WsException("endpoint is empty");
        }
        $this->key = $key;
        $this->secret = $secret;
        $this->endpoint = $endpoint;
    }


    /**
     * Uploads the $content object to OSS.
     *
     * @param string $object objcet name
     * @param string $content The content object
     * @param array $options
     * @return null
     * @throws WsException|RequestCore_Exception
     */
    public function putObject(string $object, $content, $options = NULL)
    {
        $this->preCheckCommon($object, $options);

        $options[self::OSS_CONTENT] = $content;

        $options[self::OSS_METHOD] = self::OSS_HTTP_PUT;
        $options[self::OSS_OBJECT] = $object;

        if (!isset($options[self::OSS_LENGTH])) {
            $options[self::OSS_CONTENT_LENGTH] = strlen($options[self::OSS_CONTENT]);
        } else {
            $options[self::OSS_CONTENT_LENGTH] = $options[self::OSS_LENGTH];
        }

        if (!isset($options[self::OSS_CONTENT_TYPE])) {
            $options[self::OSS_CONTENT_TYPE] = $this->getMimeType($object);
        }
        $response = $this->auth($options);

        if (isset($options[self::OSS_CALLBACK]) && !empty($options[self::OSS_CALLBACK])) {
            $result = new CallbackResult($response);
        } else {
            $result = new PutSetDeleteResult($response);
        }

        return $result->getData();
    }

    /**
     * @throws WsException
     * @throws RequestCore_Exception
     */
    public function getObjectMeta($object, $options = NULL)
    {
        $this->preCheckCommon($object, $options);
        $options[self::OSS_METHOD] = self::OSS_HTTP_HEAD;
        $options[self::OSS_OBJECT] = $object;
        $response = $this->auth($options);
        $result = new HeaderResult($response);
        return $result->getData();
    }
    /**
     * @throws WsException
     * @throws RequestCore_Exception
     */
    public function doesObjectExist($object, $options = NULL)
    {
        $this->preCheckCommon($object, $options);
        $options[self::OSS_METHOD] = self::OSS_HTTP_HEAD;
        $options[self::OSS_OBJECT] = $object;
        $response = $this->auth($options);
        $result = new ExistResult($response);
        return $result->getData();
    }
    /**
     * Deletes a object
     *
     * @param string $object object name
     * @param array $options
     * @return null
     * @throws WsException
     * @throws RequestCore_Exception
     */
    public function deleteObject(string $object, $options = NULL)
    {
        $this->preCheckCommon($object, $options);
        $options[self::OSS_METHOD] = self::OSS_HTTP_DELETE;
        $options[self::OSS_OBJECT] = $object;
        $response = $this->auth($options);
        $result = new PutSetDeleteResult($response);
        return $result->getData();
    }
    /**
     * @throws WsException
     */
    private function preCheckCommon($object, &$options, $isCheckObject = true)
    {
        if ($isCheckObject) {
            $this->preCheckObject($object);
        }
        $this->preCheckOptions($options);
    }

    /**
     * validates object parameter
     *
     * @param string $object
     * @throws WsException
     */
    private function preCheckObject($object)
    {
        OssUtil::throwOssExceptionWithMessageIfEmpty($object, "object name is empty");
    }

    /**
     * validates options. Create a empty array if it's NULL.
     *
     * @param array $options
     * @throws WsException
     */
    private function preCheckOptions(&$options)
    {
        OssUtil::validateOptions($options);
        if (!$options) {
            $options = array();
        }
    }

    /**
     * @param string $object
     * @param null $file
     * @return string
     */
    private function getMimeType(string $object, $file = null): string
    {
        if (!is_null($file)) {
            $type = MimeTypes::getMimetype($file);
            if (!is_null($type)) {
                return $type;
            }
        }

        $type = MimeTypes::getMimetype($object);
        if (!is_null($type)) {
            return $type;
        }

        return self::DEFAULT_CONTENT_TYPE;
    }

    /**
     * Validates and executes the request according to OSS API protocol.
     *
     * @param array $options
     * @return ResponseCore
     * @throws WsException
     * @throws RequestCore_Exception
     */
    private function auth(array $options): ResponseCore
    {
        OssUtil::validateOptions($options);
        //Validates bucket, not required for list_bucket
        //Validates object
        $this->authPrecheckObject($options);
        //object name encoding must be UTF-8
        $this->authPreCheckObjectEncoding($options);
        $hostname = $this->generateHostname();

        $headers = $this->generateHeaders($options, $hostname);
        $signable_query_string_params = $this->generateSignableQueryStringParam($options);
        $signable_query_string = OssUtil::toQueryString($signable_query_string_params);
        $resource_uri = $this->generateResourceUri($options);
        //Generates the URL (add query parameters)
        $conjunction = '?';
        $non_signable_resource = '';
        if ($signable_query_string !== '') {
            $signable_query_string = $conjunction . $signable_query_string;
            $conjunction = '&';
        }
        $query_string = $this->generateQueryString($options);
        if ($query_string !== '') {
            $non_signable_resource .= $conjunction . $query_string;
            $conjunction = '&';
        }
        $requestUrl = $this->endpoint . $resource_uri . $signable_query_string . $non_signable_resource;
        //Creates the request
        $request = new RequestCore($requestUrl, $this->requestProxy);
        $request->set_useragent($this->generateUserAgent());
        // Streaming uploads
        if (isset($options[self::OSS_FILE_UPLOAD])) {
            if (is_resource($options[self::OSS_FILE_UPLOAD])) {
                $length = null;

                if (isset($options[self::OSS_CONTENT_LENGTH])) {
                    $length = $options[self::OSS_CONTENT_LENGTH];
                } elseif (isset($options[self::OSS_SEEK_TO])) {
                    $stats = fstat($options[self::OSS_FILE_UPLOAD]);
                    if ($stats && $stats[self::OSS_SIZE] >= 0) {
                        $length = $stats[self::OSS_SIZE] - (integer)$options[self::OSS_SEEK_TO];
                    }
                }
                $request->set_read_stream($options[self::OSS_FILE_UPLOAD], $length);
            } else {
                $request->set_read_file($options[self::OSS_FILE_UPLOAD]);
                $length = $request->read_stream_size;
                if (isset($options[self::OSS_CONTENT_LENGTH])) {
                    $length = $options[self::OSS_CONTENT_LENGTH];
                } elseif (isset($options[self::OSS_SEEK_TO]) && isset($length)) {
                    $length -= (integer)$options[self::OSS_SEEK_TO];
                }
                $request->set_read_stream_size($length);
            }
        }
        if (isset($options[self::OSS_SEEK_TO])) {
            $request->set_seek_position((integer)$options[self::OSS_SEEK_TO]);
        }
        if (isset($options[self::OSS_FILE_DOWNLOAD])) {
            if (is_resource($options[self::OSS_FILE_DOWNLOAD])) {
                $request->set_write_stream($options[self::OSS_FILE_DOWNLOAD]);
            } else {
                $request->set_write_file($options[self::OSS_FILE_DOWNLOAD]);
            }
        }

        if (isset($options[self::OSS_METHOD])) {
            $request->set_method($options[self::OSS_METHOD]);
        }

        if (isset($options[self::OSS_CONTENT])) {
            $request->set_body($options[self::OSS_CONTENT]);
            if ($headers[self::OSS_CONTENT_TYPE] === 'application/x-www-form-urlencoded') {
                $headers[self::OSS_CONTENT_TYPE] = 'application/octet-stream';
            }

            $headers[self::OSS_CONTENT_LENGTH] = strlen($options[self::OSS_CONTENT]);
            $headers[self::OSS_CONTENT_MD5] = base64_encode(md5($options[self::OSS_CONTENT], true));
        }

        if (!isset($headers[self::OSS_ACCEPT_ENCODING])) {
            $headers[self::OSS_ACCEPT_ENCODING] = '';
        }

        uksort($headers, 'strnatcasecmp');

        foreach ($headers as $header_key => $header_value) {
            $header_value = str_replace(array("\r", "\n"), '', $header_value);
            if ($header_value !== '' || $header_key === self::OSS_ACCEPT_ENCODING) {
                $request->add_header($header_key, $header_value);
            }
        }

        $timestamps = strtotime($headers[self::OSS_DATE]);
        $signature = base64_encode(hash_hmac('sha1', $timestamps, $this->secret, true));
        $request->add_header('Authorization', 'Bearer ' . $this->key . ':' . $signature);
        $request->add_header('Authorization', 'Bearer ' . $this->key . ':' . $signature);
        $request->add_header('x-authorization', 'Bearer ' . $this->key . ':' . $signature);

        if ($this->timeout !== 0) {
            $request->timeout = $this->timeout;
        }

        try {
            $request->send_request();
        } catch (RequestCore_Exception $e) {
            throw(new WsException('RequestCoreException: ' . $e->getMessage()));
        }
        $response_header = $request->get_response_header();
        $data = new ResponseCore($response_header, $request->get_response_body(), $request->get_response_code());
        //retry if OSS Internal Error
        if ((integer)$request->get_response_code() === 500) {
            if ($this->redirects < $this->maxRetries) {
                //Sets the sleep time betwen each retry.
                $delay = (integer)(pow(4, $this->redirects) * 100000);
                usleep($delay);
                $this->redirects++;
                $data = $this->auth($options);
            }
        }

        $this->redirects = 0;
        return $data;
    }

    /**
     *
     * Validates the object name--throw OssException if it's invalid.
     *
     * @param $options
     * @throws WsException
     */
    private function authPrecheckObject($options)
    {
        if (isset($options[self::OSS_OBJECT]) && $options[self::OSS_OBJECT] === '/') {
            return;
        }

        if (isset($options[self::OSS_OBJECT]) && !OssUtil::validateObject($options[self::OSS_OBJECT])) {
            throw new WsException('"' . $options[self::OSS_OBJECT] . '"' . ' object name is invalid');
        }
    }

    /**
     * Checks the object's encoding. Convert it to UTF8 if it's in GBK or GB2312
     *
     * @param mixed $options parameter
     */
    private function authPreCheckObjectEncoding(&$options)
    {
        $tmp_object = $options[self::OSS_OBJECT];
        try {
            if (OssUtil::isGb2312($options[self::OSS_OBJECT])) {
                $options[self::OSS_OBJECT] = iconv('GB2312', "UTF-8//IGNORE", $options[self::OSS_OBJECT]);
            } elseif (OssUtil::checkChar($options[self::OSS_OBJECT], true)) {
                $options[self::OSS_OBJECT] = iconv('GBK', "UTF-8//IGNORE", $options[self::OSS_OBJECT]);
            }
        } catch (\Exception $e) {
            try {
                $tmp_object = iconv(mb_detect_encoding($tmp_object), "UTF-8", $tmp_object);
            } catch (\Exception $e) {
            }
        }
        $options[self::OSS_OBJECT] = $tmp_object;
    }

    private function stringToSignSorted($string_to_sign)
    {
        $queryStringSorted = '';
        $explodeResult = explode('?', $string_to_sign);
        $index = count($explodeResult);
        if ($index === 1)
            return $string_to_sign;

        $queryStringParams = explode('&', $explodeResult[$index - 1]);
        sort($queryStringParams);

        foreach ($queryStringParams as $params) {
            $queryStringSorted .= $params . '&';
        }

        $queryStringSorted = substr($queryStringSorted, 0, -1);

        $result = '';
        for ($i = 0; $i < $index - 1; $i++) {
            $result .= $explodeResult[$i] . '?';
        }
        return $result . $queryStringSorted;
    }

    /**
     * Gets the resource Uri in the current request
     *
     * @param $options
     * @return string return the resource uri.
     */
    private function generateResourceUri($options): string
    {
        $resource_uri = "";

        // resource_uri + object
        if (isset($options[self::OSS_OBJECT]) && '/' !== $options[self::OSS_OBJECT]) {
            $resource_uri .= '/' . str_replace(array('%2F', '%25'), array('/', '%'), rawurlencode($options[self::OSS_OBJECT]));
        }

        // resource_uri + sub_resource
        $conjunction = '?';
        if (isset($options[self::OSS_SUB_RESOURCE])) {
            $resource_uri .= $conjunction . $options[self::OSS_SUB_RESOURCE];
        }
        return $resource_uri;
    }

    /**
     * generates query string
     *
     * @param mixed $options
     * @return string
     */
    private function generateQueryString($options): string
    {
        //query parameters
        $queryStringParams = array();
        if (isset($options[self::OSS_QUERY_STRING])) {
            $queryStringParams = array_merge($queryStringParams, $options[self::OSS_QUERY_STRING]);
        }
        return OssUtil::toQueryString($queryStringParams);
    }

    /**
     * Initialize headers
     *
     * @param mixed $options
     * @param string $hostname hostname
     * @return array
     */
    private function generateHeaders($options, string $hostname): array
    {
        $headers = array(
            self::OSS_CONTENT_TYPE => $options[self::OSS_CONTENT_TYPE] ?? self::DEFAULT_CONTENT_TYPE,
            self::OSS_DATE => $options[self::OSS_DATE] ?? gmdate('D, d M Y H:i:s \G\M\T'),
            self::OSS_HOST => $hostname,
        );
        if (isset($options[self::OSS_CONTENT_MD5])) {
            $headers[self::OSS_CONTENT_MD5] = $options[self::OSS_CONTENT_MD5];
        }

        //Add stsSecurityToken
        if ((!is_null($this->securityToken)) && (!$this->enableStsInUrl)) {
            $headers[self::OSS_SECURITY_TOKEN] = $this->securityToken;
        }
        //Merge HTTP headers
        if (isset($options[self::OSS_HEADERS])) {
            $headers = array_merge($headers, $options[self::OSS_HEADERS]);
        }
        return $headers;
    }

    /**
     * @throws WsException
     * @throws RequestCore_Exception
     */
    public function listObjects($options = NULL)
    {
        $this->preCheckCommon(NULL, $options, false);
        $options[self::OSS_METHOD] = self::OSS_HTTP_GET;
        $options[self::OSS_OBJECT] = '/';
        $query = $options[self::OSS_QUERY_STRING] ?? array();
        $options[self::OSS_QUERY_STRING] = array_merge(
            $query,
            array(self::OSS_ENCODING_TYPE => self::OSS_ENCODING_TYPE_URL,
                self::OSS_DELIMITER => $options[self::OSS_DELIMITER] ?? '/',
                self::OSS_PREFIX => $options[self::OSS_PREFIX] ?? '',
                self::OSS_MAX_KEYS => $options[self::OSS_MAX_KEYS] ?? self::OSS_MAX_KEYS_VALUE,
                self::OSS_MARKER => $options[self::OSS_MARKER] ?? '')
        );

        $response = $this->auth($options);
        $result = new ListObjectsResult($response);
        return $result->getData();
    }

    /**
     * @param $object
     * @param null $options
     * @return null
     * @throws RequestCore_Exception
     * @throws WsException
     */
    public function createObjectDir($object, $options = NULL)
    {
        $this->preCheckCommon($object, $options);
        $options[self::OSS_METHOD] = self::OSS_HTTP_PUT;
        $options[self::OSS_OBJECT] = $object . '/';
        $options[self::OSS_CONTENT_LENGTH] = array(self::OSS_CONTENT_LENGTH => 0);
        $response = $this->auth($options);
        $result = new PutSetDeleteResult($response);
        return $result->getData();
    }


    /**
     * @throws WsException
     * @throws RequestCore_Exception
     */
    public function getObject($object, $options = NULL)
    {
        $this->preCheckCommon($object, $options);
        $options[self::OSS_METHOD] = self::OSS_HTTP_GET;
        $options[self::OSS_OBJECT] = $object;
        if (isset($options[self::OSS_LAST_MODIFIED])) {
            $options[self::OSS_HEADERS][self::OSS_IF_MODIFIED_SINCE] = $options[self::OSS_LAST_MODIFIED];
            unset($options[self::OSS_LAST_MODIFIED]);
        }
        if (isset($options[self::OSS_ETAG])) {
            $options[self::OSS_HEADERS][self::OSS_IF_NONE_MATCH] = $options[self::OSS_ETAG];
            unset($options[self::OSS_ETAG]);
        }
        if (isset($options[self::OSS_RANGE])) {
            $range = $options[self::OSS_RANGE];
            $options[self::OSS_HEADERS][self::OSS_RANGE] = "bytes=$range";
            unset($options[self::OSS_RANGE]);
        }
        $response = $this->auth($options);
        $result = new BodyResult($response);
        return $result->getData();
    }

    /**
     * @return string
     */
    private function generateHostname(): string
    {
        $arr = parse_url($this->endpoint);
        return $arr['host']??'';
    }
    /**
     * Generates UserAgent
     *
     * @return string
     */
    private function generateUserAgent(): string
    {
        return self::OSS_NAME . "/" . self::OSS_VERSION . " (" . php_uname('s') . "/" . php_uname('r') . "/" . php_uname('m') . ";" . PHP_VERSION . ")";
    }
    /**
     * Generates the signalbe query string parameters in array type
     *
     * @param array $options
     * @return array
     */
    private function generateSignableQueryStringParam($options): array
    {
        $signableQueryStringParams = array();
        $signableList = array(
            self::OSS_PART_NUM,
            'response-content-type',
            'response-content-language',
            'response-cache-control',
            'response-content-encoding',
            'response-expires',
            'response-content-disposition',
            self::OSS_UPLOAD_ID,
            self::OSS_COMP,
            self::OSS_LIVE_CHANNEL_STATUS,
            self::OSS_LIVE_CHANNEL_START_TIME,
            self::OSS_LIVE_CHANNEL_END_TIME,
            self::OSS_POSITION,
            self::OSS_SYMLINK,
            self::OSS_RESTORE,
            self::OSS_TAGGING,
            self::OSS_WORM_ID,
            self::OSS_VERSION_ID,
        );

        foreach ($signableList as $item) {
            if (isset($options[$item])) {
                $signableQueryStringParams[$item] = $options[$item];
            }
        }

        if ($this->enableStsInUrl && (!is_null($this->securityToken))) {
            $signableQueryStringParams["security-token"] = $this->securityToken;
        }

        return $signableQueryStringParams;
    }
    // Constants for Life cycle
    const OSS_LIFECYCLE_EXPIRATION = "Expiration";
    const OSS_LIFECYCLE_TIMING_DAYS = "Days";
    const OSS_LIFECYCLE_TIMING_DATE = "Date";
    //OSS Internal constants
    const OSS_OBJECT = 'object';
    const OSS_HEADERS = OssUtil::OSS_HEADERS;
    const OSS_METHOD = 'method';
    const OSS_QUERY = 'query';
    const OSS_BASENAME = 'basename';
    const OSS_MAX_KEYS = 'max-keys';
    const OSS_UPLOAD_ID = 'uploadId';
    const OSS_PART_NUM = 'partNumber';
    const OSS_COMP = 'comp';
    const OSS_LIVE_CHANNEL_STATUS = 'status';
    const OSS_LIVE_CHANNEL_START_TIME = 'startTime';
    const OSS_LIVE_CHANNEL_END_TIME = 'endTime';
    const OSS_POSITION = 'position';
    const OSS_MAX_KEYS_VALUE = 100;
    const OSS_MAX_OBJECT_GROUP_VALUE = OssUtil::OSS_MAX_OBJECT_GROUP_VALUE;
    const OSS_MAX_PART_SIZE = OssUtil::OSS_MAX_PART_SIZE;
    const OSS_MID_PART_SIZE = OssUtil::OSS_MID_PART_SIZE;
    const OSS_MIN_PART_SIZE = OssUtil::OSS_MIN_PART_SIZE;
    const OSS_FILE_SLICE_SIZE = 8192;
    const OSS_PREFIX = 'prefix';
    const OSS_DELIMITER = 'delimiter';
    const OSS_MARKER = 'marker';
    const OSS_ACCEPT_ENCODING = 'Accept-Encoding';
    const OSS_CONTENT_MD5 = 'Content-Md5';
    const OSS_SELF_CONTENT_MD5 = 'x-oss-meta-md5';
    const OSS_CONTENT_TYPE = 'Content-Type';
    const OSS_CONTENT_LENGTH = 'Content-Length';
    const OSS_IF_MODIFIED_SINCE = 'If-Modified-Since';
    const OSS_IF_UNMODIFIED_SINCE = 'If-Unmodified-Since';
    const OSS_CACHE_CONTROL = 'Cache-Control';
    const OSS_PREAUTH = 'preauth';
    const OSS_CONTENT_COING = 'Content-Coding';
    const OSS_CONTENT_DISPOSTION = 'Content-Disposition';
    const OSS_RANGE = 'range';
    const OSS_ETAG = 'etag';
    const OSS_LAST_MODIFIED = 'lastmodified';
    const OS_CONTENT_RANGE = 'Content-Range';
    const OSS_CONTENT = OssUtil::OSS_CONTENT;
    const OSS_BODY = 'body';
    const OSS_LENGTH = OssUtil::OSS_LENGTH;
    const OSS_HOST = 'Host';
    const OSS_DATE = 'Date';
    const OSS_AUTHORIZATION = 'Authorization';
    const OSS_FILE_DOWNLOAD = 'fileDownload';
    const OSS_FILE_UPLOAD = 'fileUpload';
    const OSS_PART_SIZE = 'partSize';
    const OSS_SEEK_TO = 'seekTo';
    const OSS_SIZE = 'size';
    const OSS_QUERY_STRING = 'query_string';
    const OSS_SUB_RESOURCE = 'sub_resource';
    const OSS_DEFAULT_PREFIX = 'x-oss-';
    const DEFAULT_CONTENT_TYPE = 'application/octet-stream';
    const OSS_SYMLINK_TARGET = 'x-oss-symlink-target';
    const OSS_SYMLINK = 'symlink';
    const OSS_HTTP_CODE = 'http_code';
    const OSS_INFO = 'info';
    const OSS_STORAGE = 'storage';
    const OSS_RESTORE = 'restore';
    const OSS_STORAGE_STANDARD = 'Standard';
    const OSS_STORAGE_ARCHIVE = 'Archive';
    const OSS_STORAGE_COLDARCHIVE = 'ColdArchive';
    const OSS_TAGGING = 'tagging';
    const OSS_WORM_ID = 'wormId';
    const OSS_RESTORE_CONFIG = 'restore-config';
    const OSS_KEY_MARKER = 'key-marker';
    const OSS_VERSION_ID_MARKER = 'version-id-marker';
    const OSS_VERSION_ID = 'versionId';
    const OSS_HEADER_VERSION_ID = 'x-oss-version-id';

    //private URLs
    const OSS_URL_ACCESS_KEY_ID = 'OSSAccessKeyId';
    const OSS_URL_EXPIRES = 'Expires';
    const OSS_URL_SIGNATURE = 'Signature';
    //HTTP METHOD
    const OSS_HTTP_GET = 'GET';
    const OSS_HTTP_PUT = 'PUT';
    const OSS_HTTP_HEAD = 'HEAD';
    const OSS_HTTP_POST = 'POST';
    const OSS_HTTP_DELETE = 'DELETE';
    const OSS_HTTP_OPTIONS = 'OPTIONS';
    //Others
    const OSS_MULTI_PART = 'uploads';
    const OSS_MULTI_DELETE = 'delete';
    const OSS_CALLBACK = "x-oss-callback";
    const OSS_CALLBACK_VAR = "x-oss-callback-var";
    //Constants for STS SecurityToken
    const OSS_SECURITY_TOKEN = "x-oss-security-token";
    const OSS_ACL_TYPE_PRIVATE = 'private';
    const OSS_ACL_TYPE_PUBLIC_READ = 'public-read';
    const OSS_ACL_TYPE_PUBLIC_READ_WRITE = 'public-read-write';
    const OSS_ENCODING_TYPE = "encoding-type";
    const OSS_ENCODING_TYPE_URL = "url";

    // Domain Types
    const OSS_HOST_TYPE_NORMAL = "normal";//http://bucket.oss-cn-hangzhou.aliyuncs.com/object
    const OSS_HOST_TYPE_IP = "ip";  //http://1.1.1.1/bucket/object
    const OSS_HOST_TYPE_SPECIAL = 'special'; //http://bucket.guizhou.gov/object
    const OSS_HOST_TYPE_CNAME = "cname";  //http://mydomain.com/object
    //OSS ACL array
    static $OSS_ACL_TYPES = array(
        self::OSS_ACL_TYPE_PRIVATE,
        self::OSS_ACL_TYPE_PUBLIC_READ,
        self::OSS_ACL_TYPE_PUBLIC_READ_WRITE
    );
    // OssClient version information
    const OSS_NAME = "ws-sdk-php";
    const OSS_VERSION = "2.4.3";
    const OSS_BUILD = "20210825";
    const OSS_AUTHOR = "";
    const OSS_OPTIONS_ORIGIN = 'Origin';
    const OSS_OPTIONS_REQUEST_METHOD = 'Access-Control-Request-Method';
    const OSS_OPTIONS_REQUEST_HEADERS = 'Access-Control-Request-Headers';

    // user's domain type. It could be one of the four: OSS_HOST_TYPE_NORMAL, OSS_HOST_TYPE_IP, OSS_HOST_TYPE_SPECIAL, OSS_HOST_TYPE_CNAME
    private $hostType = self::OSS_HOST_TYPE_NORMAL;
    private $requestProxy = null;
    private $hostname;
    private $securityToken;
    private $enableStsInUrl = false;
    private $timeout = 0;
    private $connectTimeout = 0;


}
