<?

class cache extends Memcache {

    public $save_path;

    function  __construct( $cache_servers ) {
        foreach ($cache_servers as $hoststr) {
            $t = explode(':',$host);
            $host = $t[0];
            if (is_numeric($t[1])) $port = $t[1];
            else $port = 11211;
            $this->addServer($host, $port);
            if ($this->save_path) $this->save_path .= ',';
            $this->save_path .= $host . ':' . $port;
        }
    }

    function set( $key, $var, $duration=0 ) {
        if ($duration) {
            $time = time();
            $num_seconds = strtotime('+'.$duration,$time) - $time;
        }
        $success = $this->replace( $key, $var, null, $num_seconds );
        if( !$success ) {
            $success = $this->set( $key, $var, null, $num_seconds );
        }
        return $success;
    }

    function get( $key ) {
        return $this->get( $key );
    }

}

