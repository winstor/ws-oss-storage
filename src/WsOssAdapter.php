<?php
/**
 * Created by PhpStorm.
 * User: jwb
 * Date: 2021/5/26
 * Time: 9:08
 */

namespace Winstor\WsOSS;


use Winstor\WsOSS\Core\WsException;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;

class WsOssAdapter extends AbstractAdapter
{
    protected $debug;
    /**
     * @var array
     */
    protected static $resultMap = [
        'Body' => 'raw_contents',
        'Content-Length' => 'size',
        'ContentType' => 'mimetype',
        'Size' => 'size',
        'StorageClass' => 'storage_class',
    ];

    /**
     * @var array
     */
    protected static $metaOptions = [
        'CacheControl',
        'Expires',
        'ServerSideEncryption',
        'Metadata',
        'ACL',
        'ContentType',
        'ContentDisposition',
        'ContentLanguage',
        'ContentEncoding',
    ];

    protected static $metaMap = [
        'CacheControl' => 'Cache-Control',
        'Expires' => 'Expires',
        'ServerSideEncryption' => 'x-oss-server-side-encryption',
        'Metadata' => 'x-oss-metadata-directive',
        'ACL' => 'x-oss-object-acl',
        'ContentType' => 'Content-Type',
        'ContentDisposition' => 'Content-Disposition',
        'ContentLanguage' => 'response-content-language',
        'ContentEncoding' => 'Content-Encoding',
    ];
    /**
     * @var WsClient
     */
    protected $client;
    /**
     * @var string
     */
    protected $endPoint;
    //配置
    protected $options = [
        'Multipart' => 128
    ];


    /**
     * WsOssAdapter constructor.
     * @param WsClient $client
     * @param string $endPoint
     * @param null $prefix
     * @param array $options
     */
    public function __construct(WsClient $client, string $endPoint, $prefix = null, array $options = [])
    {
        $this->client = $client;
        $this->setPathPrefix($prefix);
        $this->endPoint = $endPoint;
        $this->options = $options;
    }

    /**
     * @throws WsException
     * @throws Http\RequestCore_Exception
     */
    public function has($path)
    {
        $object = $this->applyPathPrefix($path);

        return $this->client->doesObjectExist($object);
    }

    /**
     * @throws WsException
     * @throws Http\RequestCore_Exception
     */
    public function read($path)
    {
        $result = $this->readObject($path);
        $result['contents'] = (string)$result['raw_contents'];
        unset($result['raw_contents']);
        return $result;
    }

    /**
     * @param string $path
     * @throws Http\RequestCore_Exception
     * @throws WsException
     */
    public function readStream($path)
    {
        $result = $this->readObject($path);
        $result['stream'] = $result['raw_contents'];
        //rewind($result['stream']);
        // Ensure the EntityBody object destruction doesn't close the stream
        //$result['raw_contents']->detachStream();
        unset($result['raw_contents']);

        return $result;
    }

    public function listContents($directory = '', $recursive = false)
    {
        return $this->listDirObjects($directory, $recursive);
    }

    /**
     * @param string $path
     * @throws Http\RequestCore_Exception
     */
    public function getMetadata($path)
    {
        $object = $this->applyPathPrefix($path);

        try {
            $objectMeta = $this->client->getObjectMeta($object);
        } catch (WsException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }

        return $objectMeta;
    }

    public function getSize($path)
    {
        $object = $this->getMetadata($path);
        $object['size'] = $object['content-length'];
        return $object;
    }

    public function getMimetype($path)
    {
        if ($object = $this->getMetadata($path))
            $object['mimetype'] = $object['content-type'];
        return $object;
    }

    public function getTimestamp($path)
    {
        if ($object = $this->getMetadata($path))
            $object['timestamp'] = strtotime($object['last-modified']);
        return $object;
    }

    public function getVisibility($path)
    {
        return AdapterInterface::VISIBILITY_PUBLIC;
    }


    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     * @return array|false|string[]
     * @throws Http\RequestCore_Exception
     */
    public function write($path, $contents, Config $config)
    {
        $object = $this->applyPathPrefix($path);
        $options = $this->getOptions($this->options, $config);

        if (!isset($options[WsClient::OSS_LENGTH])) {
            $options[WsClient::OSS_LENGTH] = Util::contentSize($contents);
        }
        if (!isset($options[WsClient::OSS_CONTENT_TYPE])) {
            $options[WsClient::OSS_CONTENT_TYPE] = Util::guessMimeType($path, $contents);
        }
        try {
            $this->client->putObject($object, $contents, $options);
        } catch (WsException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return $this->normalizeResponse($options, $path);
    }

    /**
     * Write a new file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config)
    {
        $options = $this->getOptions($this->options, $config);

        $contents = stream_get_contents($resource);

        return $this->write($path, $contents, $config);
    }

    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    public function updateStream($path, $resource, Config $config)
    {
        $contents = stream_get_contents($resource);
        return $this->update($path, $contents, $config);
    }

    public function rename($path, $newpath)
    {
        dump(__FUNCTION__);
        dump(__METHOD__);
        if (!$this->copy($path, $newpath)) {
            return false;
        }
        return $this->delete($path);
    }

    public function copy($path, $newpath)
    {
        dump(__FUNCTION__);
        dump(__METHOD__);
        return true;
    }

    /**
     * @param string $path
     * @return bool
     * @throws Http\RequestCore_Exception
     * @throws WsException
     */
    public function delete($path): bool
    {
        $object = $this->applyPathPrefix($path);

        try {
            $this->client->deleteObject($object);
        } catch (WsException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }

        return !$this->has($path);
    }

    public function deleteDir($dirname)
    {
        dump(__FUNCTION__);
        dump(__METHOD__);
        return true;
    }

    /**
     * @param string $dirname
     * @param Config $config
     * @return array|false
     * @throws Http\RequestCore_Exception
     */
    public function createDir($dirname, Config $config)
    {
        $object = $this->applyPathPrefix($dirname);
        $options = $this->getOptionsFromConfig($config);

        try {
            $this->client->createObjectDir($object, $options);
        } catch (WsException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }

        return ['path' => $dirname, 'type' => 'dir'];
    }

    public function setVisibility($path, $visibility)
    {
        dump(__FUNCTION__);
        dump(__METHOD__);
    }

    public function getUrl($path)
    {
        if (!$this->has($path)) return '';
        return $this->endPoint . '/' . ltrim($path, '/');
    }


    /**
     * @param string $path
     * @return string[]
     * @throws Http\RequestCore_Exception
     * @throws WsException
     */
    protected function readObject(string $path): array
    {
        $object = $this->applyPathPrefix($path);

        $result['Body'] = $this->client->getObject($object);
        $result = array_merge($result, ['type' => 'file']);
        return $this->normalizeResponse($result, $path);
    }

    /**
     * Get options for a OSS call. done
     *
     * @param array $options
     *
     * @return array OSS options
     */
    protected function getOptions(array $options = [], Config $config = null): array
    {
        $options = array_merge($this->options, $options);

        if ($config) {
            $options = array_merge($options, $this->getOptionsFromConfig($config));
        }

        return array(WsClient::OSS_HEADERS => $options);
    }

    /**
     * Retrieve options from a Config instance. done
     *
     * @param Config $config
     *
     * @return array
     */
    protected function getOptionsFromConfig(Config $config): array
    {
        $options = [];

        foreach (static::$metaOptions as $option) {
            if (!$config->has($option)) {
                continue;
            }
            $options[static::$metaMap[$option]] = $config->get($option);
        }

        if ($visibility = $config->get('visibility')) {
            // For local reference
            // $options['visibility'] = $visibility;
            // For external reference
            $options['x-oss-object-acl'] = $visibility === AdapterInterface::VISIBILITY_PUBLIC ? WsClient::OSS_ACL_TYPE_PUBLIC_READ : WsClient::OSS_ACL_TYPE_PRIVATE;
        }

        if ($mimetype = $config->get('mimetype')) {
            // For local reference
            // $options['mimetype'] = $mimetype;
            // For external reference
            $options['Content-Type'] = $mimetype;
        }

        return $options;
    }

    /**
     * Normalize a result from OSS.
     *
     * @param array $object
     * @param string $path
     *
     * @return array file metadata
     */
    protected function normalizeResponse(array $object, $path = null): array
    {
        $result = ['path' => $path ?: $this->removePathPrefix($object['Key'] ?? $object['Prefix'])];
        $result['dirname'] = Util::dirname($result['path']);
        if (isset($object['LastModified'])) {
            $result['timestamp'] = strtotime($object['LastModified']);
        }
        if (substr($result['path'], -1) === '/') {
            $result['type'] = 'dir';
            $result['path'] = rtrim($result['path'], '/');

            return $result;
        }
        return array_merge($result, Util::map($object, static::$resultMap), ['type' => 'file']);
    }

    /**
     * @param $fun string function name : __FUNCTION__
     * @param $e
     */
    protected function logErr($fun, $e)
    {
        if ($this->debug) {
            Log::error($fun . ": FAILED");
            Log::error($e->getMessage());
        }
    }

    /**
     * 列举文件夹内文件列表；可递归获取子文件夹；
     * @param string $dirname 目录
     * @param bool $recursive 是否递归
     * @return mixed
     * @throws WsException
     */
    public function listDirObjects(string $dirname = '', bool $recursive = false)
    {
        //存储结果
        $result = [];
        $options = ['prefix' => $dirname];
        try {
            $fileList = $this->client->listObjects($options);
            foreach ($fileList as $item) {
                $result[] = $item;
                if ($recursive && $item['type'] == 'dir') {
                    $next = $this->listDirObjects($item['path'], $recursive);
                    $result = array_merge($result, $next);
                }
            }
        } catch (WsException $e) {
            $this->logErr(__FUNCTION__, $e);
            // return false;
            throw $e;
        }
        return $result;
    }

}
