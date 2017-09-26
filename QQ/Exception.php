<?php
/**
 *
 * QQ支付API异常类
 * @author widyhu
 *
 */
class QQException extends Exception {
    public function errorMessage()
    {
        return $this->getMessage();
    }
}
