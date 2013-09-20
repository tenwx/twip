<?php
require('include/twitteroauth.php');
require('image_proxy.php');
class twip{
    const PARENT_API = 'https://api.twitter.com/';
    const PARENT_SEARCH_API = 'http://search.twitter.com/';
    const ERR_LOGFILE = 'err.txt';
    const LOGFILE = 'log.txt';
    const LOGTIMEZONE = 'Etc/GMT-8';
    const BASE_URL = 'http://yegle.net/twip/';
    const API_VERSION = '1.1';

    public function replace_tco_json(&$status){
        if(!isset($status->entities)){
            return;
        }
        mb_internal_encoding('UTF-8');

        if(isset($status->entities->urls)){
            $a = array_reverse($status->entities->urls);
            foreach($a as &$url){
                if($url->expanded_url){
                    $status->text = mb_substr($status->text, 0, $url->indices[0]) . $url->expanded_url . mb_substr($status->text, $url->indices[1]);
                    $url->indices[1] = $url->indices[0] + mb_strlen($url->expanded_url);
                    $url->url = $url->expanded_url;
                }
            }
            $status->entities->urls = array_reverse($a);
        }

        if(!isset($status->entities->media)){
            return;
        }
        $a = array_reverse($status->entities->media);
        foreach($status->entities->media as &$media){
            $status->text = mb_substr($status->text, 0, $media->indices[0]) . $media->media_url_https . mb_substr($status->text, $media->indices[1]);
            $media->indices[1] = $media->indices[0] + mb_strlen($media->media_url_https);
            $media->url = $media->media_url_https;
        }
        $status->entities->media = array_reverse($a);
    }

    public function json_x86_decode($in){
        $in = preg_replace('/id":(\d+)/', 'id":"\1"', $in);
        return json_decode($in);
    }
    public function json_x86_encode($in){
        $in = json_encode($in);
        return preg_replace('/id":"(\d+)"/', 'id":\1', $in);
    }

    public function parse_entities($status, $type){
        if($this->o_mode_parse_entities && $type == 'json'){
            $j = is_string($status) ? $this->json_x86_decode($status) : $status;
            if(is_array($j)){
                foreach($j as &$s){
                    $s = $this->parse_entities($s, $type);
                }
            }
            else {
                $this->replace_tco_json($j);
                if(isset($j->status)){
                    $this->replace_tco_json($j->status);
                }
                if(isset($j->retweeted_status)){
                    $this->replace_tco_json($j->retweeted_status);
                }
                if(isset($j->status->retweeted_status)){
                    $this->replace_tco_json($j->status->retweeted_status);
                }
            }
            return is_string($status) ? $this->json_x86_encode($j) : $j;
        }
        return $status;
    }

    public function twip($options = null){
        $this->parse_variables($options);

        ob_start();
        $compressed = $this->compress && Extension_Loaded('zlib') && ob_start("ob_gzhandler");

        if($this->mode=='t'){
            $this->transparent_mode();
        }
        else if($this->mode=='o'){
            $this->override_mode();
        }
        else if($this->mode=='i'){
            $this->override_mode(true);
        }
        else{
            header('HTTP/1.0 400 Bad Request');
            exit();
        }

        $str = ob_get_contents();
        if ($compressed) ob_end_flush();
        header('Content-Length: '.ob_get_length());
        ob_flush();

        if($this->debug){
            print_r($this);
            print_r($_SERVER);
            file_put_contents('debug',ob_get_contents().$str);
            ob_clean();
        }
        if($this->dolog){
            file_put_contents('log',$this->method.' '.$this->request_uri."\n",FILE_APPEND);
        }
    }

    private function echo_token(){
            $str = 'oauth_token='.$this->access_token['oauth_token']."&oauth_token_secret=".$this->access_token['oauth_token_secret']."&user_id=".$this->access_token['user_id']."&screen_name=".$this->access_token['screen_name'].'&x_auth_expires=0'."\n";
            echo $str;
    }

    private function parse_variables($options){
        //parse options
        $this->parent_api = isset($options['parent_api']) ? $options['parent_api'] : self::PARENT_API;
        $this->parent_search_api = isset($options['parent_search_api']) ? $options['parent_search_api'] : self::PARENT_SEARCH_API;
        $this->api_version = isset($options['api_version']) ? $options['api_version'] : self::API_VERSION;
        $this->debug = isset($options['debug']) ? !!$options['debug'] : FALSE;
        $this->dolog = isset($options['dolog']) ? !!$options['dolog'] : FALSE;
        $this->compress = isset($options['compress']) ? !!$options['compress'] : FALSE;
        $this->oauth_key = $options['oauth_key'];
        $this->oauth_secret = $options['oauth_secret'];
        $this->o_mode_parse_entities = isset($options['o_mode_parse_entities']) ? !!$options['o_mode_parse_entities'] : FALSE;

        if(substr($this->parent_api, -1) !== '/') $this->parent_api .= '/';
        if(substr($this->parent_search_api, -1) !== '/') $this->parent_search_api .= '/';

        $this->base_url = isset($options['base_url']) ? trim($options['base_url'],'/').'/' : self::BASE_URL;
        if(preg_match('/^https?:\/\//i',$this->base_url) == 0){
            $this->base_url = 'http://'.$this->base_url;
        }

        //parse $_SERVER
        $this->method = $_SERVER['REQUEST_METHOD'];


        $this->parse_request_uri();
    }

    private function override_mode($imageproxy = FALSE){
        $tokenfile = glob('oauth/'.$this->password.'.*');
        if(!empty($tokenfile)){
            $access_token = @file_get_contents($tokenfile[0]);
        }
        if(empty($access_token)){
            header('HTTP/1.1 401 Unauthorized');
            header('WWW-Authenticate: Basic realm="Twip4 Override Mode"');
            echo 'You are not allowed to use this API proxy';
            exit();
        }
        $access_token = unserialize($access_token);
        $this->access_token = $access_token;

        if(preg_match('!oauth/access_token\??!', $this->request_uri)){
            $this->echo_token();
            return;
        }

        if($imageproxy){
            if($this->method=='POST'){
                echo imageUpload($this->oauth_key, $this->oauth_secret, $this->access_token);
            }else{
                echo 'The image proxy needs POST method.';
            }
            return;
        }

        if($this->request_uri == null){
            echo 'click <a href="'.$this->base_url.'oauth.php">HERE</a> to get your API url';
            return;
        }
        $this->parameters = $this->get_parameters();
        $this->uri_fixer();
        $this->connection = new TwitterOAuth($this->oauth_key, $this->oauth_secret, $this->access_token['oauth_token'], $this->access_token['oauth_token_secret']);


        // Process with update_with_media
        if($this->method === 'POST' && strpos($this->request_uri,'statuses/update_with_media') !== FALSE) {
            $this->request_headers = OAuthUtil::get_headers();

            // Check actually media uplaod
            if(strpos(@$this->request_headers['Content-Type'], 'multipart/form-data') !== FALSE
                && count($_FILES) > 0 && isset($_FILES['media'])) {

                $header_authorization = $this->connection->getOAuthRequest($this->request_uri, $this->method, null)->to_header();
                $this->forwarded_headers = array("Host: api.twitter.com", $header_authorization, "Expect:");
                $this->parameters = preg_replace('/^@/', "\0@", $_POST);

                $media = $_FILES['media'];
                $fn = is_array($media['tmp_name']) ? $media['tmp_name'][0] : $media['tmp_name'];
                $this->parameters["media[]"] = '@' . $fn;

                $ch = curl_init($this->request_uri);
                curl_setopt($ch,CURLOPT_HTTPHEADER,$this->forwarded_headers);
                curl_setopt($ch,CURLOPT_HEADERFUNCTION,array($this,'headerfunction'));
                curl_setopt($ch,CURLOPT_POSTFIELDS,$this->parameters);
                curl_setopt($ch,CURLOPT_RETURNTRANSFER,TRUE);
                $ret = curl_exec($ch);

                echo $ret;
                return;
            } else {
                header('HTTP/1.0 400 Bad Request');
                return;
            }
        }

        if(preg_match('/^[^?]+\.json/', $this->request_uri)){
            $type = 'json';
        } else {
            $type = 'xml';
        }

        // Add include_entities arg if not exists and API is configured to expand t.co
        if($this->o_mode_parse_entities && !isset($_REQUEST['include_entities'])){
            if(preg_match('/^[^?]+\?/', $this->request_uri)){
                $this->request_uri .= '&include_entities=true';
            }
            else{
                $this->request_uri .= '?include_entities=true';
            }
        }

        switch($this->method){
            case 'POST':
                echo $this->parse_entities($this->connection->post($this->request_uri,$this->parameters), $type);
                break;
            case 'DELETE':
                echo $this->parse_entities($this->connection->delete($this->request_uri,$this->parameters), $type);
                break;
            default:
                echo $this->parse_entities($this->connection->get($this->request_uri), $type);
                break;
        }
    }

    private function transparent_mode(){
        $this->uri_fixer();
        $ch = curl_init($this->request_uri);
        $this->request_headers = OAuthUtil::get_headers();

        // Don't parse POST arguments as array if emulating a browser submit
        if(isset($this->request_headers['Content-Type']) && 
                strpos($this->request_headers['Content-Type'], 'application/x-www-form-urlencoded') !== FALSE){
            $this->parameters = $this->get_parameters(false);
        }else{
            $this->parameters = $this->get_parameters(true);
        }

        // Process Upload image (currently only first file will proxy to Twitter)
        if(strpos($this->request_uri,'statuses/update_with_media') !== FALSE &&
            strpos(@$this->request_headers['Content-Type'], 'multipart/form-data') !== FALSE) {

            $this->parameters = preg_replace('/^@/', "\0@", $_POST);
            if(count($_FILES) > 0 && isset($_FILES['media'])) {
                $media = $_FILES['media'];
                $fn = is_array($media['tmp_name']) ? $media['tmp_name'][0] : $media['tmp_name'];
                $this->parameters["media[]"] = '@' . $fn;
                unset($this->request_headers['Content-Type']);
            }
        }

        $forwarded_headers = array(
            'User-Agent',
            'Authorization',
            'Content-Type',
            'X-Forwarded-For',
            'Expect',
            );
        foreach($forwarded_headers as $header){
            if(isset($this->request_headers[$header])){
                $this->forwarded_headers[] = $header.': '.$this->request_headers[$header];
            }
        }
        if(!isset($this->forwarded_headers['Expect'])) $this->forwarded_headers[] = 'Expect:';
        curl_setopt($ch,CURLOPT_HTTPHEADER,$this->forwarded_headers);
        curl_setopt($ch,CURLOPT_HEADERFUNCTION,array($this,'headerfunction'));
        if($this->method != 'GET'){
            curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$this->method);
            curl_setopt($ch,CURLOPT_POSTFIELDS,$this->parameters);
        }
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,TRUE);
        $ret = curl_exec($ch);
        //fixme:redirect request back to twip,this is nasty and insecure...
        if(strpos($this->request_uri,'oauth/authorize?oauth_token=')!==FALSE){
            $ret = str_replace('<form action="https://api.twitter.com/oauth/authorize"','<form action="'.$this->base_url.'t/oauth/authorize"',$ret);
            $ret = str_replace('<div id="signin_form">','<h1><strong style="color:red">Warning!This page is proxied by twip and therefore you may leak your password to API proxy owner!</strong></h1><div id="signin_form">',$ret);
        }
        echo $ret;
    }

    private function uri_fixer(){
        // $api is the API request without version number
        list($version, $api) = $this->extract_uri_version($this->request_uri);

        // If user specified version, use that version. Else use default version
        $version = ($version == "") ? $this->api_version : $version;

        $this->request_headers['Host'] = 'api.twitter.com';

        if($version === "1") {
            if((strpos($this->request_uri,'search.') === 0)){
                $this->request_headers['Host'] = 'search.twitter.com';
            }

            if(strpos($this->request_uri,'statuses/update_with_media') > 0){
                $this->request_uri = str_replace("api.twitter.com", "upload.twitter.com", $this->request_uri);
            }
        }

        $replacement = array(
            'pc=true' => 'pc=false', //change pc=true to pc=false
            '&earned=true' => '', //remove "&earned=true"
            '/1.1/mentions.json' => '/1.1/mentions_timeline.json', //backward compat for API 1.0
            'i/search.json' => 'search.json', //fix search issue on twitter for iPhone
        );

        $api = str_replace(array_keys($replacement), array_values($replacement), $api);


        if((strpos($api,'search.') === 0)){
            $this->request_uri = sprintf("%s%s", $this->parent_search_api, $api);
        }
        else{
            if( strpos($api,'oauth/') === 0
                || strpos($api, 'i/') === 0 ){
                // These API requests don't needs version string
                $this->request_uri = sprintf("%s%s", $this->parent_api, $api);
            }else{
                $this->request_uri = sprintf("%s%s/%s", $this->parent_api, $version, $api);
            }
        }


    }

    public function extract_uri_version($uri){
        $re = '/^(([0-9.]+)\/)?(.*)/';

        preg_match($re, $uri, $matches);

        $version = $matches[2];
        $api = $matches[3];
        return array($version, $api);
    }

    private function parse_request_uri(){
        $full_request_uri = substr($_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'],strlen(preg_replace('/^https?:\/\//i','',$this->base_url)));
        if(strpos($full_request_uri,'o/')===0){
            list($this->mode,$this->password,$this->request_uri) = explode('/',$full_request_uri,3);
            $this->mode = 'o';
        }
        elseif(strpos($full_request_uri,'t/')===0){
            list($this->mode,$this->request_uri) = explode('/',$full_request_uri,2);
            $this->mode = 't';
        }
        elseif(strpos($full_request_uri,'i/')===0){
            list($this->mode,$this->password,$this->request_uri) = explode('/',$full_request_uri,3);
            $this->mode = 'i';
        }
        $this->request_uri = preg_replace('/\/+/','/',$this->request_uri);
    }

    private function headerfunction($ch,$str){
        if(strpos($str,'Content-Length:')!==FALSE){
            header($str);
        }
        $this->response_headers[] = $str;
        return strlen($str);
    }

    private function get_parameters($returnArray = TRUE){
        $data = file_get_contents('php://input');
        if(!$returnArray) return $data;
        $ret = array();
        parse_str($data,$ret);
        return $ret;
    }
}
?>
