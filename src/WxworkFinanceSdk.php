<?php
class WxworkFinanceSdk
{
    private FFI $lib;
    private ?FFI\CData $sdk = null;
    private array $options = [
        'proxy_host'     => "",
        'proxy_password' => "",
        'timeout' => 10, // 默认超时时间为10s
    ];

    /** 
     * string $corpId 企业号
     * string $secret 秘钥
     *
     * 可选参数
     * array $options = [ 
     *   'proxy_host' => string,
     *   'proxy_password' => string,
     *   'timeout' => 10, // 默认超时时间为10s
     * ]
     */
    public function __construct(string $corpId, string $secret, array $options = [])
    {
        $libDir = dirname(__DIR__) . '/C_sdk';
        $header = file_get_contents($libDir.'/WeWorkFinanceSdk_C.h');
        $pattern = '/^#(ifn?def) +(.*?)\n([\s\S]*?)(#endif)/m';
        $transformedHeader = $header;
        $transformedHeader = preg_replace_callback($pattern, function (array $matches): string {
            [, $keyword, , $body] = $matches;
            if ($keyword === 'ifdef') {
                $body = '';
            } elseif ($keyword === 'ifndef') {
                $body = '';
            }
            return $body;
        }, $transformedHeader);
        
        $this->lib = FFI::cdef(
            $transformedHeader,
            $libDir."/libWeWorkFinanceSdk_C.so"
        );
        
        $this->sdk = $this->lib->NewSdk();
        $ret = $this->lib->Init($this->sdk, $corpId, $secret);
        
        if ($ret != 0) {
            // sdk需要主动释放
            $this->lib->DestroySdk($this->sdk);
            $this->sdk = null;
            $msg = sprintf("init sdk err ret: %d\n", $ret);
            throw new WxworkFinanceSdkException($msg);
        }

        $this->options = array_merge($this->options, $options);
    }

    public function __destruct()
    {
        // sdk需要主动释放
        if(!is_null($this->sdk)) {
            $this->lib->DestroySdk($this->sdk);
        }
    }

    public function getChatData(int $seq = 0, int $limit = 10): string 
    {
        $chatDatas = $this->lib->NewSlice();
        $ret = $this->lib->GetChatData($this->sdk, $seq, $limit, 
                $this->options['proxy_host'], 
                $this->options['proxy_password'], 
                $this->options['timeout'],
                $chatDatas);
        if ($ret != 0) {
            $this->lib->FreeSlice($chatDatas);
            $msg = sprintf("GetChatData err ret:%d\n", $ret);
            throw new WxworkFinanceSdkException($msg);
        }
        // printf("GetChatData len:%d data:%s\n", $chatDatas->len, FFI::string($chatDatas->buf));
        $data = FFI::string($this->lib->GetContentFromSlice($chatDatas), $this->lib->GetSliceLen($chatDatas));
        $this->lib->FreeSlice($chatDatas);
        return $data;
    }

    /**
     * 下载资源
     * $sdkfileid 资源ID，来自chat中的数据sdkfileid
     * $saveTo 本地保存的路径
     */
    public function downloadMedia(string $sdkfileid, string $saveTo): bool
    {
        $mediaDatas = $this->lib->NewMediaData();
        $fp = fopen($saveTo, "wb");
        do {
            $ret = $this->lib->GetMediaData($this->sdk, $this->lib->GetOutIndexBuf($mediaDatas), 
                    $sdkfileid, 
                    $this->options['proxy_host'], 
                    $this->options['proxy_password'], 
                    $this->options['timeout'],
                    $mediaDatas
                );
            if (0 != $ret) {
                $this->lib->FreeMediaData($mediaDatas);
                fclose($fp) && unlink($saveTo);
                $msg = sprintf("sdk get media data err, ret: %d\n", $ret);
                throw new WxworkFinanceSdkException($msg);
            }
            // FILE_APPEND
            $data = FFI::string($this->lib->GetData($mediaDatas), $this->lib->GetDataLen($mediaDatas));
            fwrite($fp, $data, $this->lib->GetDataLen($mediaDatas));
        } while($this->lib->IsMediaDataFinish($mediaDatas) == 0);
       fclose($fp);
       $this->lib->FreeMediaData($mediaDatas);
       return true;
    }

    /**  
     * 拉取静态资源数据，用于可以支持追加模式的三方存储平台
     * 
     * 返回的数据结构体
     * $ret = [
     *  'data' => '' // string 返回的数据
     *  'nextIndex' => 'ddd' // string 获取下一段数据的句柄
     *  'isFinished' => int // 1 数据已拉取完毕 
     * ];
    */
    public function getMediaData(string $sdkfileid, string $indexBuf = ""): array
    {
        $mediaDatas = $this->lib->NewMediaData();
        $ret = $this->lib->GetMediaData($this->sdk, $indexBuf, $sdkfileid,
                    $this->options['proxy_host'], 
                    $this->options['proxy_password'], 
                    $this->options['timeout'],
                    $mediaDatas
                );
        if (0 != $ret) {
            $this->lib->FreeMediaData($mediaDatas);
            $msg = sprintf("sdk get media data err, ret: %d\n", $ret);
            throw new WxworkFinanceSdkException($msg);
        }
        $data = FFI::string($this->lib->GetData($mediaDatas), $this->lib->GetDataLen($mediaDatas));
        $nextIndex = $this->lib->GetOutIndexBuf($mediaDatas);
        $isFinished = $this->lib->IsMediaDataFinish($mediaDatas) == 1 ?: 0;

        $this->lib->FreeMediaData($mediaDatas);
        return [
            "data" => $data,
            "nextIndex" => FFI::string($nextIndex),
            "isFinished" => $isFinished,
        ];
    }

    /**
     * 解密数据
     *  $randomKey 通过openssl解密后的key
     *  $encryptStr chats 的加密数据
     */
    public function decryptData(string $randomKey, string $encryptStr): string
    {
        $chatDatas = $this->lib->NewSlice();
        $ret = $this->lib->DecryptData($randomKey, $encryptStr, $chatDatas);
        $data = FFI::string($this->lib->GetContentFromSlice($chatDatas), $this->lib->GetSliceLen($chatDatas));
        $this->lib->FreeSlice($chatDatas);
        return $data;
    }
}