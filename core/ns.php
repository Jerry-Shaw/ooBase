<?php

/**
 * NS System script
 *
 * Copyright 2016-2019 Jerry Shaw <jerry-shaw@live.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace core;

//Require PHP version >= 7.2.0
if (version_compare(PHP_VERSION, '7.2.0', '<')) {
    exit('NervSys needs PHP 7.2.0 or higher!');
}

//Define NervSys version
define('SYSVER', '7.4.0 Beta');

//Define system root path
define('SYSROOT', dirname(__DIR__));

//Find entry script file path
$entry_script = false === strpos($_SERVER['SCRIPT_FILENAME'], $cwd_path = getcwd())
    ? $cwd_path . DIRECTORY_SEPARATOR . $_SERVER['SCRIPT_FILENAME']
    : $_SERVER['SCRIPT_FILENAME'];

//Find true ROOT path
$sys_path   = explode(DIRECTORY_SEPARATOR, SYSROOT);
$entry_path = explode(DIRECTORY_SEPARATOR, $entry_script);
$root_path  = implode(DIRECTORY_SEPARATOR, array_intersect($sys_path, $entry_path));

//Define ROOT path
define('ROOT', $root_path);

//Define entry script file path
define('ENTRY_SCRIPT', $entry_script);

//Free memory
unset($entry_script, $cwd_path, $sys_path, $entry_path, $root_path);

//Define JSON formats
define('JSON_FORMAT', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
define('JSON_PRETTY', JSON_FORMAT | JSON_PRETTY_PRINT);

//Set only include path to ROOT/include
set_include_path(ROOT . DIRECTORY_SEPARATOR . 'include');

//Register autoload function
spl_autoload_register(
    static function (string $class): void
    {
        //Load class file without namespace directly from include path
        if (false === strpos($class, '\\')) {
            require $class . '.php';
            return;
        }

        //Get relative path of target class file
        $file = strtr($class, '\\', DIRECTORY_SEPARATOR) . '.php';

        //Load class file from SYSROOT or ROOT
        foreach ([ROOT, SYSROOT] as $path) {
            if (is_file($class_file = $path . DIRECTORY_SEPARATOR . $file)) {
                require $class_file;
                break;
            }
        }

        unset($class, $file, $path, $class_file);
    }
);

//Load libraries
use core\lib\pool;
use core\lib\stc\error;
use core\lib\stc\factory;

//Register error handler
register_shutdown_function([error::class, 'shutdown_handler']);
set_exception_handler([error::class, 'exception_handler']);
set_error_handler([error::class, 'error_handler']);

/**
 * Class ns
 *
 * @package core
 */
class ns
{
    //Customized libraries
    public static $io     = core\lib\io::class;
    public static $log    = core\lib\log::class;
    public static $router = core\lib\router::class;

    //App path
    public static $app_path = ROOT . DIRECTORY_SEPARATOR . 'app';

    //Runtime values
    public static $is_CLI = true;
    public static $is_TLS = true;

    //Default setting
    const CONF = [
        //Sys setting
        'sys'  => [
            'timezone'  => 'UTC',
            'auto_call' => true
        ],
        //Log setting
        'log'  => [
            'emergency' => true,
            'alert'     => true,
            'critical'  => true,
            'error'     => true,
            'warning'   => true,
            'notice'    => true,
            'info'      => true,
            'debug'     => true,
            'display'   => true
        ],
        //Other settings
        'cgi'  => [],
        'cli'  => [],
        'cors' => [],
        'init' => [],
        'call' => []
    ];

    /**
     * System boot
     *
     * @param bool $output
     */
    public static function boot(bool $output = true): void
    {
        //Load app.ini
        $conf = self::load_ini();

        //Get runtime values
        self::$is_CLI = 'cli' === PHP_SAPI;
        self::$is_TLS = (isset($_SERVER['HTTPS']) && 'on' === $_SERVER['HTTPS'])
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO']);

        //Set default timezone
        date_default_timezone_set($conf['sys']['timezone']);

        //Verify CORS
        if (!self::pass_cors($conf['cors'])) {
            exit;
        }

        //Call CGI script

        //Call CLI script
        if (self::$is_CLI) {

        }

        //No output
        if (!$output) {
            return;
        }


        //Output result




    }


    /**
     * Load app.ini
     */
    private static function load_ini(): array
    {
        /** @var \core\lib\pool $pool */
        $pool = factory::build(pool::class);

        //Set default conf values
        $pool->conf = self::CONF;

        //Read app.ini
        if (is_file($app_ini = self::$app_path . DIRECTORY_SEPARATOR . 'app.ini')) {
            $app_conf = parse_ini_file($app_ini, true, INI_SCANNER_TYPED);

            foreach ($app_conf as $key => $value) {
                $key = strtolower($key);

                //Update conf values
                $pool->conf[$key] = array_replace_recursive($pool->conf[$key], $value);
            }

            unset($app_conf, $key, $value);
        }

        unset($app_ini);
        return $pool->conf;
    }

    /**
     * Check CORS permission
     *
     * @param array $cors_conf
     *
     * @return bool
     */
    private static function pass_cors(array $cors_conf): bool
    {
        //Skip CLI script
        if (self::$is_CLI) {
            return true;
        }

        //Check Server ENV
        if (!isset($_SERVER['HTTP_ORIGIN'])
            || $_SERVER['HTTP_ORIGIN'] === (self::$is_TLS ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) {
            return true;
        }

        //Exit on access NOT permitted
        if (is_null($allow_headers = $cors_conf[$_SERVER['HTTP_ORIGIN']] ?? $cors_conf['*'] ?? null)) {
            return false;
        }

        //Response access allowed headers
        header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
        header('Access-Control-Allow-Headers: ' . $allow_headers);
        header('Access-Control-Allow-Credentials: true');

        //Exit on OPTION request
        if ('OPTIONS' === $_SERVER['REQUEST_METHOD']) {
            return false;
        }

        unset($cors_conf, $allow_headers);
        return true;
    }
}