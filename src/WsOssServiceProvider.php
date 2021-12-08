<?php
/**
 * Created by PhpStorm.
 * User: jwb
 * Date: 2021/5/26
 * Time: 9:12
 */

namespace Winstor\WsOSS;

use Winstor\WsOSS\Plugins\PutFile;
use Winstor\WsOSS\Plugins\PutRemoteFile;
use League\Flysystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;


class WsOssServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     * @throws Core\WsException
     */
    public function boot()
    {
        //发布配置文件
        Storage::extend('wss', function ($app, $config) {
            $debug = !empty($config['debug']) && $config['debug'];
            if ($debug) Log::debug('wss config:', $config);
            $key  = $config['key'];
            $secret = $config['secret'];
            $endpoint  = $config['endpoint'];

            $client  = new WsClient($key, $secret, $endpoint);
            $adapter = new WsOssAdapter($client,$endpoint);
            $filesystem = new Filesystem($adapter);

            $filesystem->addPlugin(new PutFile());
            $filesystem->addPlugin(new PutRemoteFile());

            return $filesystem;
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {

    }
}
