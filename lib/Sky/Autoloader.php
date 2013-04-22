<?php

namespace Sky;

/**
 *  This autoloader loads classes that have namespaces.  All namespaces
 *  map to the lib/ folder.
 */
class Autoloader {

    /**
     *  loads namespaced class files
     *  lib/
     *  @param string $name
     *  @return boolean
     */
    public static function namespaceLoader($name) {
        $filename = "lib/" . str_replace('\\', '/', $name) . ".php";
        #d($filename);
        if (file_exists_incpath($filename)) {
            include $filename;
            if (class_exists($name)) return true;
        }
        return false;
    }

    /**
     *  loads class files into the global space
     *  lib/class/class.{name}.php
     *  @param string $name
     *  @return boolean
     */
    public static function globalLoader($name) {
        $file_path = 'lib/class/class.'.$name.'.php';
        if (file_exists_incpath($file_path)) {
            include $file_path;
            if (class_exists($name)) return true;
        }
        return false;
    }

    /**
     *  loads aql model classes
     *  models/{name}/class.{name}.php
     *  @param string $name
     *  @return boolean
     *  @global $sky_aql_model_path
     */
    public static function globalAqlModelLoader($name) {
        global $sky_aql_model_path;
        $path = sprintf('%s/%s/class.%s.php', $sky_aql_model_path, $name, $name);
        if (file_exists_incpath($path)) {
            include $path;
            if (class_exists($name)) return true;
        }
        return false;
    }

}
