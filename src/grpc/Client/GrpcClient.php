<?php
namespace Imi\Grpc\Client;

use Imi\Bean\BeanFactory;
use Imi\Grpc\Parser;
use Imi\Rpc\Client\IService;
use Imi\Rpc\Client\IRpcClient;
use Imi\Util\Uri;
use Yurun\Util\HttpRequest;
use Yurun\Util\YurunHttp\Http2\SwooleClient;

/**
 * gRPC 客户端
 */
class GrpcClient implements IRpcClient
{
    /**
     * 配置
     *
     * @var array
     */
    protected $options;

    /**
     * Http2 客户端
     *
     * @var \Yurun\Util\YurunHttp\Http2\SwooleClient
     */
    protected $http2Client;

    /**
     * url
     *
     * @var string
     */
    protected $url;

    /**
     * uri 对象
     *
     * @var \Imi\Util\Uri
     */
    protected $uri;

    /**
     * 请求方法
     *
     * @var string
     */
    protected $requestMethod;

    /**
     * HttpRequest
     *
     * @var \Yurun\Util\HttpRequest
     */
    protected $httpRequest;

    /**
     * 构造方法
     *
     * @param array $options 配置
     */
    public function __construct($options)
    {
        if(!isset($options['url']))
        {
            throw new \InvalidArgumentException('Missing [url] parameter');
        }
        $this->url = $options['url'];
        $this->uri = new Uri($this->url);
        $this->requestMethod = $options['method'] ?? 'GET';
        $this->options = $options;
    }

    /**
     * 打开
     * @return boolean
     */
    public function open()
    {
        $this->httpRequest = new HttpRequest;
        $this->http2Client = new SwooleClient($this->uri->getHost(), Uri::getServerPort($this->uri), 'https' === $this->uri->getScheme());
        return $this->http2Client->connect();
    }

    /**
     * 关闭
     * @return void
     */
    public function close()
    {
        $this->http2Client->close();
    }

    /**
     * 是否已连接
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->http2Client->isConnected();
    }

    /**
     * 获取实例对象
     *
     * @return \Yurun\Util\YurunHttp\Http2\SwooleClient
     */
    public function getInstance()
    {
        return $this->http2Client;
    }

    /**
     * 获取服务对象
     *
     * @param string $name 服务名
     * @return \Imi\Rpc\Client\IService
     */
    public function getService($name = null): IService
    {
        return BeanFactory::newInstance(GrpcService::class, $this, ...func_get_args());
    }

    /**
     * 获取配置
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * 发送请求
     *
     * $metadata 格式：['key' => ['value']]
     * 
     * @param string $package
     * @param string $service
     * @param string $name
     * @param \Google\Protobuf\Internal\Message $message
     * @param array $metadata
     * @return int|bool
     */
    public function send($package, $service, $name, \Google\Protobuf\Internal\Message $message, $metadata = [])
    {
        $url = $this->buildRequestUrl($package, $service, $name);
        $content = Parser::serializeMessage($message);
        $request = $this->httpRequest->buildRequest($url, $content, $this->requestMethod);
        if($metadata)
        {
            foreach($metadata as $k => $v)
            {
                $request = $request->withHeader($k, $v);
            }
        }
        return $this->http2Client->send($request);
    }

    /**
     * 接收响应结果
     *
     * @param string $responseClass
     * @param int $streamId
     * @param double|null $timeout
     * @return \Google\Protobuf\Internal\Message
     */
    public function recv($responseClass, $streamId = -1, $timeout = null)
    {
        $result = $this->http2Client->recv($streamId, $timeout);
        if(!$result || !$result->success)
        {
            throw new \RuntimeException(sprintf('gRPC recv() failed, errCode:%s, errorMsg:%s', $result->getErrno(), $result->getError()));
        }
        return Parser::deserializeMessage([$responseClass, 'decode'], $result->body());
    }

    /**
     * 构建请求URL
     *
     * @param string $package
     * @param string $service
     * @param string $name
     * @return string
     */
    public function buildRequestUrl($package, $service, $name)
    {
        return strtr($this->url, [
            '{package}' =>  $package,
            '{service}' =>  $service,
            '{name}'    =>  $name,
        ]);
    }

}