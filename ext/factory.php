<?php

/**
 * Factory Extension
 *
 * Copyright 2016-2019 秋水之冰 <27206617@qq.com>
 * Copyright 2016-2019 vicky <904428723@qq.com>
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

namespace ext;

class factory
{
    /**
     * Get new object from called class
     * Defined by class and arguments
     *
     * @return $this
     */
    public static function new(): object
    {
        return \core\lib\stc\factory::build(get_called_class(), func_get_args());
    }


    //Factory storage
    private static $storage = [];

    /**
     * Get original object from called class with alias
     * Defined by class created from "as"
     *
     * @param string $alias
     *
     * @return $this
     */
    public static function use(string $alias): object
    {
        if (!isset(self::$storage[$key = self::get_alias(get_called_class(), $alias)])) {
            error::exception_handler(new \Exception('Object "' . get_called_class() . ':' . $alias . '" NOT found!', E_USER_ERROR));
        }

        unset($alias);
        return self::$storage[$key];
    }


    /**
     * Obtain original object from another class
     * Defined by class and arguments
     *
     * @param string $class
     * @param array  $param
     *
     * @return object
     */
    public static function obtain(string $class, array $param = []): object
    {
        return self::get_stock(__FUNCTION__, $class, $param);
    }

    /**
     * Configure class properties
     *
     * @param array $setting
     *
     * @return $this
     */
    public function config(array $setting): object
    {
        $setting = array_intersect_key($setting, get_object_vars($this));

        foreach ($setting as $key => $val) {
            $this->$key = $val;
        }

        unset($setting, $key, $val);
        return $this;
    }

    /**
     * Copy object as alias (overwrite)
     *
     * @param string $alias
     *
     * @return $this
     */
    public function as(string $alias): object
    {
        if (isset(self::$storage[$key = self::get_alias(get_class($this), $alias)])) {
            self::$storage[$key] = null;
            unset(self::$storage[$key]);
        }

        self::$storage[$key] = &$this;

        unset($alias, $key);
        return $this;
    }

    /**
     * Free from alias storage
     *
     * @param string $alias
     */
    public function free(string $alias = ''): void
    {
        $list = '' === $alias ? array_keys(self::$storage, $this, true) : [self::get_alias(get_class($this), $alias)];

        foreach ($list as $key) {
            self::$storage[$key] = null;
            unset(self::$storage[$key]);
        }

        unset($alias, $list, $key);
    }

    /**
     * Stock controller
     *
     * @param string $type
     * @param string $class
     * @param array  $param
     *
     * @return object
     */
    private static function get_stock(string $type, string $class, array $param): object
    {
        if (!isset(self::$storage[$key = hash('md5', $type . ':' . $class . ':' . json_encode($param))])) {
            self::$storage[$key] = !empty($param) ? new $class(...$param) : new $class();
        }

        unset($type, $class, $param);
        return self::$storage[$key];
    }

    /**
     * Get alias key for a class
     *
     * @param string $class
     * @param string $alias
     *
     * @return string
     */
    private static function get_alias(string $class, string $alias): string
    {
        $key = hash('md5', $class . ':' . $alias);

        unset($class, $alias);
        return $key;
    }
}