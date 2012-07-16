<?php

namespace Sky\Memcache;

class Connection
{

    /**
     * @var Memcache connection
     */
    protected static $instance = null;

    /**
     * @var string
     */
    protected static $save_path = '';

    /**
     * @var int
     */
    public static $default_port = 11211;

    /**
     * @var array
     */
    public static $default_settings = array(
        'allow_failover' => 1,
        // 'redundancy' => 1,
        'hash_strategy' => 'consistent',
        'servers' => array(
            'port' => 11211,
            'udp_port' => 0,
            'persistent' => true,
            'weight' => 1,
            'timeout' => 1,
            'retry_interval' => 15,
            'status' => true,
            'callback_failure' => null,
            'timeoutms' => null
        )
    );

    /**
     * Uses mergeSettings if necessary to set defaults from the global array
     * @return  array
     * @global  $memcache_default_settings
     */
    protected static function getDefaultSettings()
    {
        global $memcache_default_settings;
        if (!$memcache_default_settings || !is_array($memcache_default_settings)) {
            return static::$default_settings;
        }

        return static::mergeSettings(
            $memcache_default_settings,
            self::$default_settings,
            true
        );
    }

    /**
     * Merges settings configuration
     * @param   array   $settings
     * @param   array   $defaults   to use
     * @param   Boolean $def = false, if true, we're creating default settings
     *                                and returning a single associative array for servers
     *                                same format as static::$default_settings
     */
    protected static function mergeSettings(array $setting, array $default, $def = false)
    {
        foreach ($default as $key => $val) {

            if (!array_key_exists($key, $setting)) {
                $setting[$key] = $val;
            }

            if ($key != 'servers') {
                continue;
            }

            if ($def) {
                foreach ($default['servers'] as $k => $sval) {
                    if (!array_key_exists($k, $setting['servers'])) {
                        $settings['servers'][$k] = $sval;
                    }
                }
            } else {
                 foreach ($setting['servers'] as $k => $server) {
                    foreach ($default['servers'] as $server_key => $server_val) {
                        if (!array_key_exists($server_key, $server)) {
                            $setting['servers'][$k][$server_key] = $server_val;
                        }
                    }
                }

            }
        }

        return $setting;
    }

    /**
     * Attempts to make a memcache connection
     * @param   array   $config
     * @throws  \Exception  if memcache is not available and settings have been defined
     */
    public static function connect(array $config = array())
    {
        if (!$config || !$config['servers']) {
            return;
        }

        $using_pool = class_exists('\\MemcachePool');
        $using_cache = class_exists('\\Memcache');

        if (!$using_pool && !$using_cache) {
            throw new \Exception(
                'Memcache is not available on this server/php installation'
            );
        }

        // map tcp_port to port if set
        $config['servers'] = array_map(function($s) {
            if (array_key_exists('tcp_port', $s)) {
                $s['port'] = $s['tcp_port'];
                unset($s['tcp_port']);
            }
            return $s;
        }, $config['servers']);

        // preps the settings
        $config = static::mergeSettings($config, static::getDefaultSettings());
        static::debug('$memcache_settings: ' . var_export($config, true));

        // set ini
        static::setIni($config);

        // instantiate
        $cl = ($using_pool) ? '\\MemcachePool' : '\\Memcache';
        static::$instance = new $cl;

        $message = static::isPool() ? 'Using MemcachePool' : 'Using Memcache';
        static::debug($message);

        $save_path = array();

        foreach ($config['servers'] as $s) {
            static::debug('memcache::addServer: ' . var_export($s, true));
            $status = static::addServer($s);
            if ($s['status']) {
                $save_path[] = sprintf('tcp://%s:%s', $s['host'], $s['port']);
                static::debug('Successfully added: ' . $s['host']);
            } else {
                static::debug('Failed to add: ' . $s['host']);
            }
        }

        static::$save_path = implode(',', $save_path);

        if (static::$instance && !$using_pool) {
            if (!@static::$instance->getVersion()) {
                static::debug('Memcache::getVersion did not return truthy, clearing var.');
                static::$instance = null;
            }
        }
    }

    /**
     * Checks to see if using MemcachePool
     * @return  Boolean
     */
    protected static function isPool()
    {
        return (static::$instance &&
                is_object(static::$instance) &&
                get_class(static::$instance) == 'MemcachePool');
    }

    /**
     * Adds the server (generic)
     * Figures out to what method to use Pool/Memcache
     * @param   array   $s  server config
     * @return  Boolean
     * @throws  \Exception  if no instance
     */
    protected static function addServer(array $s)
    {
        if (!static::$instance) {
            throw new \Exception('Cannot add a server to no instance');
        }

        return (static::isPool())
            ? static::addHostToPool($s)
            : static::addHostToMem($s);
    }

    /**
     * Adds the server to memcachePool, if the serer is disabled,
     * it sets the server params to offline
     * @param   array   $s  server config
     * @return  Boolean
     */
    protected static function addHostToPool($s)
    {
        $status = static::$instance->addServer(
            $s['host'],
            $s['port'],
            $s['udp_port'],
            $s['persistent'],
            $s['timeout'],
            $s['retry_interval']
        );

        if (array_key_exists('status', $s) && !$s['status']) {
            $s['retry_interval'] = -1;
            $status2 = static::$instance->setServerParams(
                $s['host'],
                $s['port'],
                $s['timeout'],
                $s['retry_interval'],
                $s['status']
            );

            $m = ($status2)
                ? 'Failed to set ' . $s['host'] . ' status to offline.'
                : 'Set '. $s['host'] . ' status to offline.';

            static::debug($m);
        }

        return $status;
    }

    /**
     * Adds the server to Memcache
     * @param   array   $s  server config
     * @return  Boolean
     */
    protected static function addHostToMem(array $s)
    {
        return static::$instance->addServer(
            $s['host'],
            $s['port'],
            $s['persistent'],
            $s['weight'],
            $s['timeout'],
            $s['retry_interval'],
            $s['status'],
            $s['callback_failure'] //,
            // $s['timeoutms']
        );
    }

    /**
     * Accepts old settings and returns an array formatted to be used with connect()
     * @param   array   $servers
     * @param   int     $port
     * @param   int     $redundancy
     * @return  array
     */
    public static function reformatBCSettings($servers, $port, $redundancy)
    {
        $settings = array(
            'servers' => array()
        );

        if ($redundancy) {
            $settings['redundancy'] = $redundancy;
        }

        $port = ($port) ?: static::$default_port;

        foreach ($servers as $s) {
            $settings['servers'][] = array(
                'host' => $s,
                'port' => $port
            );
        }

        return $settings;
    }

    /**
     * Uses $map to ini_set on the values if specific keys exist
     * @param   array   $setting
     */
    protected static function setIni(array $setting)
    {
        $map = array(
            'allow_failover' => true,
            'hash_strategy' => true,
            'redundancy' => array(
                'redundancy',
                'session_redundancy'
            )
        );

        foreach ($map as $key => $action) {
            if (array_key_exists($key, $setting)) {
                $action = is_array($action) ? $action : array($key);
                foreach ($action as $k) {
                    ini_set('memcache.' . $k, $setting[$key]);
                }
            }
        }
    }

    /**
     * Ouptuts debug info if $_GET['debug']
     * @param   string  $message
     */
    public static function debug($message)
    {
        $echo = $_GET['debug'];
        if ($echo) {
            echo PHP_EOL . $message . PHP_EOL;
        }
    }

    /**
     * @return  Memcahce | MemcachePool | null
     */
    public static function getInstance()
    {
        return static::$instance;
    }

    /**
     * @return  string
     */
    public static function getSavePath()
    {
        return static::$save_path;
    }

}
