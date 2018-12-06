<?php

/**
 * Operator Handler
 *
 * Copyright 2016-2018 秋水之冰 <27206617@qq.com>
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

namespace core\handler;

use core\parser\data;
use core\parser\trustzone;

class operator extends factory
{
    /**
     * Initialize operator
     */
    public static function init(): void
    {
        try {
            //Build initial dependency
            parent::build_dep(self::$init);

            //Run dependency
            foreach (self::$init as $item) {
                self::build_caller(...$item);
            }

            unset($item);
        } catch (\Throwable $throwable) {
            //Level up Exception code to ERROR
            error::exception_handler(new \Exception($throwable->getMessage(), E_USER_ERROR));
            unset($throwable);
        }
    }

    /**
     * Run CLI process
     */
    public static function run_cli(): void
    {
        //Process orders
        foreach (parent::$cmd_cli as $key => $cmd) {
            try {
                //Prepare command
                $command = '"' . $cmd . '"';

                //Append arguments
                if (!empty(parent::$param_cli['argv'])) {
                    $command .= ' ' . implode(' ', parent::$param_cli['argv']);
                }

                //Create process
                $process = proc_open(platform::cmd_proc($command), [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

                if (!is_resource($process)) {
                    throw new \Exception($key . '=>' . $cmd . ': Access denied or command ERROR!', E_USER_ERROR);
                }

                //Send data via pipe
                if ('' !== parent::$param_cli['pipe']) {
                    fwrite($pipes[0], parent::$param_cli['pipe'] . PHP_EOL);
                }

                //Collect result
                if (parent::$param_cli['ret']) {
                    if ('' !== $data = self::read_pipe([$process, $pipes[1]])) {
                        parent::$result[$key] = &$data;
                    }

                    unset($data);
                }

                //Close pipes (ignore process)
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
            } catch (\Throwable $throwable) {
                error::exception_handler($throwable);
                unset($throwable);
            }
        }

        unset($key, $cmd, $command, $process, $pipes, $pipe);
    }

    /**
     * Run CGI process
     */
    public static function run_cgi(): void
    {
        //Build order list
        self::build_order();

        //Process orders
        while (!is_null($item_list = array_shift(parent::$cmd_cgi))) {
            //Get name & class
            $class = parent::build_name($name = array_shift($item_list));

            //Check & load class
            if (!class_exists($class, false) && !self::load_class($class)) {
                continue;
            }

            try {
                //Process dependency
                if (false !== strpos($module = strtr($name, '\\', '/'), '/')) {
                    $module = strstr($module, '/', true);
                }

                if (isset(parent::$load[$module])) {
                    $dep_list = is_string(parent::$load[$module]) ? [parent::$load[$module]] : parent::$load[$module];

                    //Build dependency
                    parent::build_dep($dep_list);

                    //Call dependency
                    foreach ($dep_list as $dep) {
                        self::build_caller(...$dep);
                    }

                    unset($dep_list, $dep);
                }

                //Check TrustZone permission
                if (empty($tz_list = trustzone::init($class))) {
                    //Do not throw out exceptions
                    continue;
                }

                //Get function list & target list
                $func_list   = get_class_methods($class);
                $target_list = !empty($item_list) ? array_intersect($item_list, $tz_list, $func_list) : array_intersect($tz_list, $func_list);

                unset($module, $tz_list, $func_list, $item_list);

                //Process target list
                foreach ($target_list as $target) {
                    //Get TrustZone data
                    $tz_dep = trustzone::get_dep($target);

                    //Build pre/post dependency
                    parent::build_dep($tz_dep['pre']);
                    parent::build_dep($tz_dep['post']);

                    //Call pre dependency
                    foreach ($tz_dep['pre'] as $tz_item) {
                        self::build_caller(...$tz_item);
                    }

                    //Verify TrustZone params
                    trustzone::verify($class, $target);

                    //Build target caller
                    self::build_caller($name, $class, $target);

                    //Call post dependency
                    foreach ($tz_dep['post'] as $tz_item) {
                        self::build_caller(...$tz_item);
                    }
                }
            } catch (\Throwable $throwable) {
                error::exception_handler($throwable);
                unset($throwable);
            }
        }

        unset($item_list, $class, $name, $target_list, $target, $tz_dep, $tz_item);
    }

    /**
     * Load class file
     *
     * @param string $class
     *
     * @return bool
     */
    private static function load_class(string $class): bool
    {
        $load = false;
        $file = trim(strtr($class, '\\', DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR) . '.php';
        $list = false !== strpos($file, DIRECTORY_SEPARATOR) ? [ROOT] : parent::$path;

        foreach ($list as $path) {
            if (is_string($path = realpath($path . $file))) {
                require $path;

                //Check class status
                if ($load = class_exists($class, false)) {
                    break;
                }
            }
        }

        unset($class, $file, $list, $path);
        return $load;
    }

    /**
     * Build mapped key
     *
     * @param string $class
     * @param string $method
     *
     * @return string
     */
    private static function build_key(string $class, string $method): string
    {
        $key = parent::$param_cgi[$class . '-' . $method] ?? (parent::$param_cgi[$class] ?? $class) . '/' . $method;

        unset($class, $method);
        return $key;
    }

    /**
     * Build CGI order list
     */
    private static function build_order(): void
    {
        $key  = -1;
        $list = [];

        foreach (parent::$cmd_cgi as $item) {
            if (false !== strpos($item, '/') || false !== strpos($item, '\\')) {
                ++$key;
            }

            $list[$key][] = $item;
        }

        parent::$cmd_cgi = &$list;
        unset($key, $list, $item);
    }

    /**
     * Build method caller
     *
     * @param string $order
     * @param string $class
     * @param string $method
     *
     * @throws \ReflectionException
     */
    private static function build_caller(string $order, string $class, string $method): void
    {
        //Reflect method
        $reflect = new \ReflectionMethod($class, $method);

        //Check visibility
        if (!$reflect->isPublic()) {
            throw new \ReflectionException($class . '::' . $method . ': NOT for public!', E_USER_WARNING);
        }

        //Get factory object
        if (!$reflect->isStatic()) {
            $class = method_exists($class, '__construct')
                ? parent::obtain($class, data::build_argv(new \ReflectionMethod($class, '__construct'), parent::$data))
                : parent::obtain($class);
        }

        //Call method (with params)
        $result = !empty($params = data::build_argv($reflect, parent::$data))
            ? forward_static_call_array([$class, $method], $params)
            : forward_static_call([$class, $method]);

        //Save result (Try mapping keys)
        if (isset($result)) {
            parent::$result[self::build_key($order, $method)] = &$result;
        }

        unset($order, $class, $method, $reflect, $params, $result);
    }

    /**
     * Get stream content
     *
     * @param array $process
     *
     * @return string
     */
    private static function read_pipe(array $process): string
    {
        $timer  = 0;
        $result = '';

        while (0 === parent::$param_cli['time'] || $timer <= parent::$param_cli['time']) {
            if (proc_get_status($process[0])['running']) {
                usleep(1000);
                $timer += 1000;
            } else {
                $result = trim(stream_get_contents($process[1]));
                break;
            }
        }

        unset($process, $timer);
        return $result;
    }
}