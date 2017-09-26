<?php

class QQPayClass
{
    protected $values;
    protected $sign;

    public function __construct()
    {
        $this->values = array();
        $this->sign = '';
        require_once 'qq1/QQException.php';
        require_once 'qq1/QQConfig.php';
    }

    /**
     * 输出xml字符
     * @throws QQException
     **/
    public function ToXml(array $arr)
    {
        if (!is_array($arr)
            || count($arr) <= 0
        ) {
            throw new QQException("数组数据异常！");
        }

        $xml = "<xml>";
        foreach ($arr as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<" . $key . ">" . $val . "</" . $key . ">";
            } else {
                $xml .= "<" . $key . "><![CDATA[" . $val . "]]></" . $key . ">";
            }
        }
        $xml .= "</xml>";
        return $xml;
    }

    /**
     *
     * 拼接签名字符串
     * @param array $urlObj
     *
     * @return 返回已经拼接好的字符串
     */
    private function ToUrlParams($urlObj)
    {
        $buff = "";
//        按照ascii码进行排序
        ksort($urlObj);
        foreach ($urlObj as $k => $v) {
            if ($k != "sign") {
                $buff .= $k . "=" . $v . "&";
            }
        }

        $buff = trim($buff, "&");
        return $buff;
    }

    /**
     *
     * 产生随机字符串，不长于32位
     * @param int $length
     * @return 产生的随机字符串
     */
    public static function getNonceStr($length = 32)
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }

    /**
     * 生成签名
     * @return 签名，本函数不覆盖sign成员变量，如要设置签名需要调用SetSign方法赋值
     */
    public function MakeSign($arr)
    {
        //签名步骤一：按字典序排序参数
        ksort($arr);
        $string = $this->ToUrlParams($arr);
        //签名步骤二：在string后加入KEY
        $string = $string . "&key=" . QQConfig::APPSECRET;
        //签名步骤三：MD5加密
        $string = md5($string);
        //签名步骤四：所有字符转为大写
        $result = strtoupper($string);
        return $result;
    }

    /**
     * 生成订单所需要的参数
     * @param array $arr
     * @return string
     */
    public function makeOrderParams(array $arr)
    {
//        添加随机字符串
        $arr['nonce_str'] = $this->makeNonceStr();
//        拼接当前的配置
        $arr_str = $this->ToUrlParams($arr);
//        生成签名
        $arr['sign'] = strtoupper(md5($arr_str . '&' . QQConfig::APPSECRET));
//        返回需要发送的结果
        return $this->ToXml($arr);
    }

    /**
     * 以post方式提交xml到对应的接口url
     *
     * @param string $xml 需要post的xml数据
     * @param string $url url
     * @param bool $useCert 是否需要证书，默认不需要
     * @param int $second url执行超时时间，默认30s
     * @throws QQException
     */
    private static function postXmlCurl($xml, $url, $useCert = false, $second = 30)
    {
        $ch = curl_init();
        //设置超时
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);

        //如果有配置代理这里就设置代理
//        if(WxPayConfig::CURL_PROXY_HOST != "0.0.0.0"
//            && WxPayConfig::CURL_PROXY_PORT != 0){
//            curl_setopt($ch,CURLOPT_PROXY, WxPayConfig::CURL_PROXY_HOST);
//            curl_setopt($ch,CURLOPT_PROXYPORT, WxPayConfig::CURL_PROXY_PORT);
//        }
        curl_setopt($ch, CURLOPT_URL, $url);
        if (stripos($url, "https://") !== FALSE) {
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);//严格校验
        }
        //设置header
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        //要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        if ($useCert == true) {
            //设置证书
            //使用证书：cert 与 key 分别属于两个.pem文件
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLCERT, QQConfig::SSLCERT_PATH);
            curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLKEY, QQConfig::SSLKEY_PATH);
        }
        //post提交方式
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        //运行curl
        $data = curl_exec($ch);
        //返回结果
        if ($data) {
            curl_close($ch);
            return $data;
        } else {
            $error = curl_errno($ch);
            curl_close($ch);
            throw new QQException("curl出错，错误码:$error");
        }
    }

    /**
     * 获取毫秒级别的时间戳
     */
    private static function getMillisecond()
    {
        //获取毫秒的时间戳
        $time = explode ( " ", microtime () );
        $time = $time[1] . ($time[0] * 1000);
        $time2 = explode( ".", $time );
        $time = $time2[0];
        return $time;
    }

    /**
     * 将xml转为array
     * @param string $xml
     * @throws QQException
     */
    public function Init($xml)
    {
        $arr = $this->FromXml($xml);
        //fix bug 2015-06-29
        if($arr['return_code'] != 'SUCCESS'){
            return $arr;
        }
        $this->CheckSign();
        return $arr;
    }

    /**
     *
     * 检测签名
     */
    public function CheckSign()
    {
        //fix异常
        if(!$this->sign){
            throw new QQException("签名错误！");
        }

        $sign = $this->MakeSign($this->values);
        if($this->sign == $sign){
            return true;
        }
        throw new QQException("签名错误！");
    }

    /**
     * 将xml转为array
     * @param string $xml
     * @throws QQException
     */
    public function FromXml($xml)
    {
        if(!$xml){
            throw new QQException("xml数据异常！");
        }
        //将XML转为array
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        $values = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return $values;
    }

    /**
     * 统一下单
     * @param array $arr
     */
    public function unifiedOrder($input, $timeOut = 6)
    {
        $url = "https://qpay.qq.com/cgi-bin/pay/qpay_unified_order.cgi";
        //检测必填参数
        if (!isset($input['out_trade_no'])) {
            throw new QQException("缺少统一支付接口必填参数out_trade_no！");
        } else if (!isset($input['body'])) {
            throw new QQException("缺少统一支付接口必填参数body！");
        } else if (!isset($input['total_fee'])) {
            throw new QQException("缺少统一支付接口必填参数total_fee！");
        } else if (!isset($input['trade_type'])) {
            throw new QQException("缺少统一支付接口必填参数trade_type！");
        }

        //关联参数
//        if($input->GetTrade_type() == "JSAPI" && !$input->IsOpenidSet()){
//            throw new QQException("统一支付接口中，缺少必填参数openid！trade_type为JSAPI时，openid为必填参数！");
//        }
//        if($input->GetTrade_type() == "NATIVE" && !$input->IsProduct_idSet()){
//            throw new QQException("统一支付接口中，缺少必填参数product_id！trade_type为JSAPI时，product_id为必填参数！");
//        }

        //异步通知url未设置，则使用配置文件中的url
        if (!isset($input['notify_url'])) {
            $input['notify_url'] = QQConfig::RETURN_URL;
        }

        $input['mch_id'] = QQConfig::MCHID;//商户号
        $input['spbill_create_ip'] = $_SERVER['REMOTE_ADDR'];//终端ip
//        $input->SetNonce_str(self::getNonceStr());//随机字符串
        $input['nonce_str'] = $this->getNonceStr();
        $input['fee_type'] = 'CNY';

        //签名
        $this->values = $input;
        $this->sign = $input['sign'] = $this->MakeSign($input);
        $xml = $this->ToXml($input);

//        $startTimeStamp = self::getMillisecond();//请求开始时间
        $response = self::postXmlCurl($xml, $url, false, $timeOut);
        $result = $this->Init($response);
//        self::reportCostTime($url, $startTimeStamp, $result);//上报请求花费时间

        return $result;
    }


}
