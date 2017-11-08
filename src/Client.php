<?php
namespace WoW\SSDB;

use WoW\SSDB\Supports\Response;
use WoW\SSDB\Exceptions\SSDBException;
use WoW\SSDB\Exceptions\ConnectException;
use WoW\SSDB\Exceptions\TimeOutException;

class Client {
    const STEP_SIZE = 0;
    const STEP_DATA = 1;

    private $debug = false;
    private $_easy = false;
    private $server = null;
    private $_closed = false;
    private $recv_buf = '';
    public $last_resp = null;
    private $batch_mode = false;
    private $batch_cmds = array();
    private $async_auth_password = null;
    public $resp = array();
    public $step;
    public $block_size;

    public function __construct($host, int $port, int $timeOut = 1440)
    {
        if (!$host || !$port) {
            throw new ConnectException("Connection parameters are wrong.");
        }

        if (is_null($this->server)) {
            $this->server = @stream_socket_client("$host:$port", $eN, $eM, (float)$timeOut/1000);

            if (!$this->server) {
                throw new ConnectException("Connection Error $eN : $eM");
            }

            $timeout_sec = intval($timeOut/1000);
            $timeout_usec = ($timeOut - $timeout_sec * 1000) * 1000;
            @stream_set_timeout($this->server, $timeout_sec, $timeout_usec);
            if(function_exists('stream_set_chunk_size')) {
                @stream_set_chunk_size($this->server, 1024 * 1024);
            }
        }
    }


    /**
     * @param int $timeOut
     */
    public function set_time_out(int $timeOut)
    {
        $timeout_sec = intval($timeOut/1000);
        $timeout_usec = ($timeOut - $timeout_sec * 1000) * 1000;
        @stream_set_timeout($this->server, $timeout_sec, $timeout_usec);
    }

    public function easy(){
        $this->_easy = true;
    }


    public function close(){
        if(!$this->_closed){
            @fclose($this->server);
            $this->_closed = true;
            $this->server = null;
        }
    }

    public function closed(){
        return $this->_closed;
    }

    public function batch(){
        $this->batch_mode = true;
        $this->batch_cmds = array();
        return $this;
    }

    public function multi(){
        return $this->batch();
    }

    public function exec(){
        $ret = array();
        foreach($this->batch_cmds as $op){
            list($cmd, $params) = $op;
            $this->send_req($cmd, $params);
        }
        foreach($this->batch_cmds as $op){
            list($cmd, $params) = $op;
            $resp = $this->recv_resp($cmd, $params);
            $resp = $this->check_easy_resp($cmd, $resp);
            $ret[] = $resp;
        }
        $this->batch_mode = false;
        $this->batch_cmds = array();
        return $ret;
    }

    public function request(){
        $args = func_get_args();
        $cmd = array_shift($args);
        return $this->__call($cmd, $args);
    }

    public function auth($password){
        $this->async_auth_password = $password;
        return null;
    }


    public function __call($cmd, $params=array()){
        $cmd = strtolower($cmd);
        if($this->async_auth_password !== null){
            $pass = $this->async_auth_password;
            $this->async_auth_password = null;
            $auth = $this->__call('auth', array($pass));
            if($auth !== true){
                throw new SSDBException("Authentication failed");
            }
        }
        if($this->batch_mode){
            $this->batch_cmds[] = array($cmd, $params);
            return $this;
        }
        try{
            if($this->send_req($cmd, $params) === false){
                $resp = new Response('error', 'send error');
            }else{
                $resp = $this->recv_resp($cmd, $params);
            }
        }catch(SSDBException $e){
            if($this->_easy){
                throw $e;
            }else{
                $resp = new Response('error', $e->getMessage());
            }
        }

        if($resp->code == 'noauth'){
            $msg = $resp->message;
            throw new SSDBException($msg);
        }

        $resp = $this->check_easy_resp($cmd, $resp);

        return $resp;
    }

    private function check_easy_resp($cmd, $resp){
        $this->last_resp = $resp;
        if($this->_easy){
            if($resp->not_found()){
                return NULL;
            }else if(!$resp->ok() && !is_array($resp->data)){
                return false;
            }else{
                return $resp->data;
            }
        }else{
            $resp->cmd = $cmd;
            return $resp;
        }
    }

    function multi_set($kvs=array()){
        $args = array();
        foreach($kvs as $k=>$v){
            $args[] = $k;
            $args[] = $v;
        }
        return $this->__call(__FUNCTION__, $args);
    }

    function multi_hset($name, $kvs=array()){
        $args = array($name);
        foreach($kvs as $k=>$v){
            $args[] = $k;
            $args[] = $v;
        }
        return $this->__call(__FUNCTION__, $args);
    }

    function multi_zset($name, $kvs=array()){
        $args = array($name);
        foreach($kvs as $k=>$v){
            $args[] = $k;
            $args[] = $v;
        }
        return $this->__call(__FUNCTION__, $args);
    }

    function incr($key, $val=1){
        $args = func_get_args();
        return $this->__call(__FUNCTION__, $args);
    }

    function decr($key, $val=1){
        $args = func_get_args();
        return $this->__call(__FUNCTION__, $args);
    }

    function zincr($name, $key, $score=1){
        $args = func_get_args();
        return $this->__call(__FUNCTION__, $args);
    }

    function zdecr($name, $key, $score=1){
        $args = func_get_args();
        return $this->__call(__FUNCTION__, $args);
    }

    function zadd($key, $score, $value){
        $args = array($key, $value, $score);
        return $this->__call('zset', $args);
    }

    function zRevRank($name, $key){
        $args = func_get_args();
        return $this->__call("zrrank", $args);
    }

    function zRevRange($name, $offset, $limit){
        $args = func_get_args();
        return $this->__call("zrrange", $args);
    }

    function hincr($name, $key, $val=1){
        $args = func_get_args();
        return $this->__call(__FUNCTION__, $args);
    }

    function hdecr($name, $key, $val=1){
        $args = func_get_args();
        return $this->__call(__FUNCTION__, $args);
    }

    private function send_req($cmd, $params){
        $req = array($cmd);
        foreach($params as $p){
            if(is_array($p)){
                $req = array_merge($req, $p);
            }else{
                $req[] = $p;
            }
        }
        return $this->send($req);
    }

    private function recv_resp($cmd, $params){
        $resp = $this->recv();
        if($resp === false){
            return new Response('error', 'Unknown error');
        }else if(!$resp){
            return new Response('disconnected', 'Connection closed');
        }
        if($resp[0] == 'noauth'){
            $errmsg = isset($resp[1])? $resp[1] : '';
            return new Response($resp[0], $errmsg);
        }
        switch($cmd){
            case 'dbsize':
            case 'ping':
            case 'qset':
            case 'getbit':
            case 'setbit':
            case 'countbit':
            case 'strlen':
            case 'set':
            case 'setx':
            case 'setnx':
            case 'zset':
            case 'hset':
            case 'qpush':
            case 'qpush_front':
            case 'qpush_back':
            case 'qtrim_front':
            case 'qtrim_back':
            case 'del':
            case 'zdel':
            case 'hdel':
            case 'hsize':
            case 'zsize':
            case 'qsize':
            case 'hclear':
            case 'zclear':
            case 'qclear':
            case 'multi_set':
            case 'multi_del':
            case 'multi_hset':
            case 'multi_hdel':
            case 'multi_zset':
            case 'multi_zdel':
            case 'incr':
            case 'decr':
            case 'zincr':
            case 'zdecr':
            case 'hincr':
            case 'hdecr':
            case 'zget':
            case 'zrank':
            case 'zrrank':
            case 'zcount':
            case 'zsum':
            case 'zremrangebyrank':
            case 'zremrangebyscore':
            case 'ttl':
            case 'expire':
                if($resp[0] == 'ok'){
                    $val = isset($resp[1])? intval($resp[1]) : 0;
                    return new Response($resp[0], $val);
                }else{
                    $errmsg = isset($resp[1])? $resp[1] : '';
                    return new Response($resp[0], $errmsg);
                }
            case 'zavg':
                if($resp[0] == 'ok'){
                    $val = isset($resp[1])? floatval($resp[1]) : (float)0;
                    return new Response($resp[0], $val);
                }else{
                    $errmsg = isset($resp[1])? $resp[1] : '';
                    return new Response($resp[0], $errmsg);
                }
            case 'get':
            case 'substr':
            case 'getset':
            case 'hget':
            case 'qget':
            case 'qfront':
            case 'qback':
                if($resp[0] == 'ok'){
                    if(count($resp) == 2){
                        return new Response('ok', $resp[1]);
                    }else{
                        return new Response('server_error', 'Invalid response');
                    }
                }else{
                    $errmsg = isset($resp[1])? $resp[1] : '';
                    return new Response($resp[0], $errmsg);
                }
                break;
            case 'qpop':
            case 'qpop_front':
            case 'qpop_back':
                if($resp[0] == 'ok'){
                    $size = 1;
                    if(isset($params[1])){
                        $size = intval($params[1]);
                    }
                    if($size <= 1){
                        if(count($resp) == 2){
                            return new Response('ok', $resp[1]);
                        }else{
                            return new Response('server_error', 'Invalid response');
                        }
                    }else{
                        $data = array_slice($resp, 1);
                        return new Response('ok', $data);
                    }
                }else{
                    $errmsg = isset($resp[1])? $resp[1] : '';
                    return new Response($resp[0], $errmsg);
                }
                break;
            case 'keys':
            case 'zkeys':
            case 'hkeys':
            case 'hlist':
            case 'zlist':
            case 'qslice':
                if($resp[0] == 'ok'){
                    $data = array();
                    if($resp[0] == 'ok'){
                        $data = array_slice($resp, 1);
                    }
                    return new Response($resp[0], $data);
                }else{
                    $errmsg = isset($resp[1])? $resp[1] : '';
                    return new Response($resp[0], $errmsg);
                }
            case 'auth':
            case 'exists':
            case 'hexists':
            case 'zexists':
                if($resp[0] == 'ok'){
                    if(count($resp) == 2){
                        return new Response('ok', (bool)$resp[1]);
                    }else{
                        return new Response('server_error', 'Invalid response');
                    }
                }else{
                    $errmsg = isset($resp[1])? $resp[1] : '';
                    return new Response($resp[0], $errmsg);
                }
                break;
            case 'multi_exists':
            case 'multi_hexists':
            case 'multi_zexists':
                if($resp[0] == 'ok'){
                    if(count($resp) % 2 == 1){
                        $data = array();
                        for($i=1; $i<count($resp); $i+=2){
                            $data[$resp[$i]] = (bool)$resp[$i + 1];
                        }
                        return new Response('ok', $data);
                    }else{
                        return new Response('server_error', 'Invalid response');
                    }
                }else{
                    $errmsg = isset($resp[1])? $resp[1] : '';
                    return new Response($resp[0], $errmsg);
                }
                break;
            case 'scan':
            case 'rscan':
            case 'zscan':
            case 'zrscan':
            case 'zrange':
            case 'zrrange':
            case 'hscan':
            case 'hrscan':
            case 'hgetall':
            case 'multi_hsize':
            case 'multi_zsize':
            case 'multi_get':
            case 'multi_hget':
            case 'multi_zget':
            case 'zpop_front':
            case 'zpop_back':
                if($resp[0] == 'ok'){
                    if(count($resp) % 2 == 1){
                        $data = array();
                        for($i=1; $i<count($resp); $i+=2){
                            if($cmd[0] == 'z'){
                                $data[$resp[$i]] = intval($resp[$i + 1]);
                            }else{
                                $data[$resp[$i]] = $resp[$i + 1];
                            }
                        }
                        return new Response('ok', $data);
                    }else{
                        return new Response('server_error', 'Invalid response');
                    }
                }else{
                    $errmsg = isset($resp[1])? $resp[1] : '';
                    return new Response($resp[0], $errmsg);
                }
                break;
            default:
                return new Response($resp[0], array_slice($resp, 1));
        }
        return new Response('error', 'Unknown command: $cmd');
    }

    function send($data){
        $ps = array();
        foreach($data as $p){
            $ps[] = strlen($p);
            $ps[] = $p;
        }
        $s = join("\n", $ps) . "\n\n";
        if($this->debug){
            echo '> ' . str_replace(array("\r", "\n"), array('\r', '\n'), $s) . "\n";
        }
        try{
            while(true){
                $ret = @fwrite($this->server, $s);
                if($ret === false || $ret === 0){
                    $this->close();
                    throw new ConnectException('Connection lost');
                }
                $s = substr($s, $ret);
                if(strlen($s) == 0){
                    break;
                }
                @fflush($this->server);
            }
        } catch (SSDBException $e) {
            $this->close();
            throw new SSDBException($e->getMessage());
        }

        return $ret;
    }

    function recv(){
        $this->step = self::STEP_SIZE;
        while(true){
            $ret = $this->parse();
            if($ret === null){
                try{
                    $data = @fread($this->server, 1024 * 1024);
                    if($this->debug){
                        echo '< ' . str_replace(array("\r", "\n"), array('\r', '\n'), $data) . "\n";
                    }
                }catch(SSDBException $e){
                    $data = '';
                }
                if($data === false || $data === ''){
                    if(feof($this->server)){
                        $this->close();
                        throw new SSDBException('Connection lost');
                    }else{
                        throw new TimeOutException('Connection timeout');
                    }
                }
                $this->recv_buf .= $data;
#				echo "read " . strlen($data) . " total: " . strlen($this->recv_buf) . "\n";
            }else{
                return $ret;
            }
        }
    }

    private function parse(){
        $spos = 0;
        $epos = 0;
        $buf_size = strlen($this->recv_buf);
        // performance issue for large reponse
        //$this->recv_buf = ltrim($this->recv_buf);
        while(true){
            $spos = $epos;
            if($this->step === self::STEP_SIZE){
                $epos = strpos($this->recv_buf, "\n", $spos);
                if($epos === false){
                    break;
                }
                $epos += 1;
                $line = substr($this->recv_buf, $spos, $epos - $spos);
                $spos = $epos;

                $line = trim($line);
                if(strlen($line) == 0){ // head end
                    $this->recv_buf = substr($this->recv_buf, $spos);
                    $ret = $this->resp;
                    $this->resp = array();
                    return $ret;
                }
                $this->block_size = intval($line);
                $this->step = self::STEP_DATA;
            }

            if($this->step === self::STEP_DATA){
                $epos = $spos + $this->block_size;
                if($epos <= $buf_size){
                    $n = strpos($this->recv_buf, "\n", $epos);
                    if($n !== false){
                        $data = substr($this->recv_buf, $spos, $epos - $spos);
                        $this->resp[] = $data;
                        $epos = $n + 1;
                        $this->step = self::STEP_SIZE;
                        continue;
                    }
                }
                break;
            }
        }

        // packet not ready
        if($spos > 0){
            $this->recv_buf = substr($this->recv_buf, $spos);
        }

        return null;
    }

    public function test()
    {
        var_dump($this->server);
    }
}