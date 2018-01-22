<?php

namespace Larabookir\Gateway\Asanpardakht;


class AES
{

    private $KEY = "Your KEY";
    private $IV = "Your IV";
    private $username = "user";
    private $password = "pass";

    function __construct($options)
    {
        date_default_timezone_set('Asia/Tehran');
        $this->KEY = $options['key'];
        $this->IV = $options['iv'];
        $this->username = $options['username'];
        $this->password = $options['password'];
    }

    function addpadding($string, $blocksize = 32)
    {
        $len = strlen($string);
        $pad = $blocksize - ($len % $blocksize);
        $string .= str_repeat(chr($pad), $pad);
        return $string;
    }
    function strippadding($string)
    {
        $slast = ord(substr($string, -1));
        $slastc = chr($slast);
        $pcheck = substr($string, -$slast);
        if(preg_match("/$slastc{".$slast."}/", $string)){
            $string = substr($string, 0, strlen($string)-$slast);
            return $string;
        } else {
            return false;
        }
    }
    function encrypt($string = "")
    {
        $key = base64_decode($this->KEY);
        $iv = base64_decode($this->IV);
        return base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $key, addpadding($string),
            MCRYPT_MODE_CBC, $iv));
    }
    function decrypt($string = "")
    {
        $key = base64_decode($this->KEY);
        $iv = base64_decode($this->IV);
        $string = base64_decode($string);
        return strippadding(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $key, $string, MCRYPT_MODE_CBC,
            $iv));
    }

}