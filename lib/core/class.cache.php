<?

class cache extends Memcache {

    protected $mc;
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

    function put( $key, $var, $duration ) {
        $time = time();
		$num_seconds = strtotime('+'.$duration,$time) - $time;
        $success = $memcache->replace( $key, $var, null, $num_seconds );
        if( !$success ) {
            $success = $memcache->set( $key, $var, null, $num_seconds );
        }
        return $success;
    }

}
