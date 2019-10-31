<?php

/**
 * Redis Queue Extension
 *
 * Copyright 2016-2019 秋水之冰 <27206617@qq.com>
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

use core\handler\platform;
use core\parser\data;

class redis_queue extends redis
{
    //Queue type
    const TYPE_DELAY    = 'delay';
    const TYPE_UNIQUE   = 'unique';
    const TYPE_REALTIME = 'realtime';

    //Wait properties
    const WAIT_IDLE = 3;
    const WAIT_SCAN = 60;

    //Process properties
    protected $max_fork = 10;
    protected $max_exec = 200;

    //Queue keys
    private $key_listen = 'RQ:listen';
    private $key_failed = 'RQ:failed';

    //Queue prefix
    private $prefix_jobs   = 'RQ:jobs:';
    private $prefix_watch  = 'RQ:watch:';
    private $prefix_worker = 'RQ:worker:';
    private $prefix_unique = 'RQ:unique:';

    //Queue delay keys
    private $key_delay_lock    = 'RQ:delay:lock';
    private $key_delay_time    = 'RQ:delay:time';
    private $prefix_delay_jobs = 'RQ:delay:jobs:';

    //Unit command
    private $unit_cmd = '';

    /**
     * redis_queue constructor.
     *
     * @param string $space_key
     */
    public function __construct(string $space_key = '')
    {
        if ('' === $space_key) {
            return;
        }

        $space_key .= ':';

        //Modify queue keys
        $this->key_listen = $space_key . $this->key_listen;
        $this->key_failed = $space_key . $this->key_failed;

        //Modify queue prefix
        $this->prefix_jobs   = $space_key . $this->prefix_jobs;
        $this->prefix_watch  = $space_key . $this->prefix_watch;
        $this->prefix_worker = $space_key . $this->prefix_worker;
        $this->prefix_unique = $space_key . $this->prefix_unique;

        //Modify queue delay keys
        $this->key_delay_lock    = $space_key . $this->key_delay_lock;
        $this->key_delay_time    = $space_key . $this->key_delay_time;
        $this->prefix_delay_jobs = $space_key . $this->prefix_delay_jobs;

        unset($space_key);
    }

    /**
     * Add job
     * Caution: Do NOT expose "add" to TrustZone directly
     *
     * @param string $cmd
     * @param array  $data
     * @param string $group
     * @param string $type default realtime
     * @param int    $time in seconds
     *
     * @return int -1: add unique job failed; 0: add job failed; other: succeeded with job length
     */
    public function add(string $cmd, array $data = [], string $group = 'main', string $type = self::TYPE_REALTIME, int $time = 0): int
    {
        //Add command
        $data['cmd'] = &$cmd;

        //Build queue data
        $queue = json_encode($data, JSON_FORMAT);

        //Check group key
        if ('' === $group) {
            $group = 'main';
        }

        //Redirect to REALTIME
        if (0 === $time) {
            $type = self::TYPE_REALTIME;
        }

        switch ($type) {
            case self::TYPE_UNIQUE:
                $result = $this->add_unique($group, $queue, $time, $cmd . (isset($data['unique']) ? ':' . $data['unique'] : ''));
                break;

            case self::TYPE_DELAY:
                $result = $this->add_delay($group, $queue, $time);
                break;

            default:
                $result = $this->add_realtime($group, $queue);
                break;
        }

        unset($cmd, $data, $group, $type, $time, $queue);
        return $result;
    }

    /**
     * Close worker process
     * Caution: Do NOT expose "close" to TrustZone directly
     *
     * @param string $key
     *
     * @return int
     */
    public function close(string $key = ''): int
    {
        //Get process list
        $process = '' === $key ? array_keys($this->show_process()) : [$this->prefix_worker . $key];

        if (empty($process)) {
            return 0;
        }

        //Remove worker from process list
        $result = call_user_func_array([$this->instance, 'del'], $process);

        //Remove worker keys
        array_unshift($process, $this->get_watch_key());
        call_user_func_array([$this->instance, 'hDel'], $process);

        unset($key, $process);
        return $result;
    }

    /**
     * Show fail list
     *
     * @param int $start
     * @param int $end
     *
     * @return array
     */
    public function show_fail(int $start = 0, int $end = -1): array
    {
        $list = [
            'len'  => $this->instance->lLen($this->key_failed),
            'data' => $this->instance->lRange($this->key_failed, $start, $end)
        ];

        unset($start, $end);
        return $list;
    }

    /**
     * Show queue length
     *
     * @param string $queue_key
     *
     * @return int
     */
    public function show_length(string $queue_key): int
    {
        return (int)$this->instance->lLen($queue_key);
    }

    /**
     * Show queue list
     *
     * @return array
     */
    public function show_queue(): array
    {
        return $this->get_keys($this->key_listen);
    }

    /**
     * Show process list
     *
     * @return array
     */
    public function show_process(): array
    {
        return $this->get_keys($this->get_watch_key());
    }

    /**
     * Start master process
     *
     * @param int $max_fork
     * @param int $max_exec
     *
     * @throws \Exception
     */
    public function start(int $max_fork = 10, int $max_exec = 200): void
    {
        //Detect env
        if (!parent::$is_CLI) {
            throw new \Exception('Run under CLI!', E_USER_ERROR);
        }

        //Set max forks
        if (0 < $max_fork) {
            $this->max_fork = &$max_fork;
        }

        //Set max executes
        if (0 < $max_exec) {
            $this->max_exec = &$max_exec;
        }

        unset($max_fork, $max_exec);

        //Get idle time
        $idle_time = $this->get_idle_time();

        //Build master hash and key
        $master_hash = hash('crc32b', uniqid(mt_rand(), true));
        $master_key  = $this->prefix_worker . ($_SERVER['HOSTNAME'] ?? 'master');

        //Exit when master process exists
        if (!$this->instance->setnx($master_key, $master_hash)) {
            exit('Already running!');
        }

        //Set process life and add to watch list
        $this->instance->expire($master_key, self::WAIT_SCAN);
        $this->instance->hSet($this->get_watch_key(), $master_key, time());

        //Close on shutdown
        register_shutdown_function([$this, 'close']);

        //Build basic unit command
        $this->unit_cmd = '"' . platform::php_path() . '" '
            . '"' . ENTRY_SCRIPT . '" --ret '
            . '--cmd="' . parent::get_app_cmd(strtr(get_class($this), '\\', '/')) . '-unit"';

        do {
            //Call delay unit
            $this->call_unit_delay();

            //Get process status
            $valid   = $this->instance->get($master_key) === $master_hash;
            $running = $this->instance->expire($master_key, self::WAIT_SCAN);

            //Idle wait on no job or unit process running
            if (empty($list = $this->show_queue()) || 1 < count($this->show_process())) {
                sleep(self::WAIT_IDLE);
                continue;
            }

            //Idle wait on no job
            if (empty($queue = $this->instance->brPop(array_keys($list), $idle_time))) {
                sleep(self::WAIT_IDLE);
                continue;
            }

            //Re-add queue job
            $this->instance->rPush($queue[0], $queue[1]);

            //Call realtime unit
            $this->call_unit_realtime();
        } while ($valid && $running);

        //On exit
        $this->close();
        unset($idle_time, $master_hash, $master_key, $valid, $running, $list, $queue);
    }

    /**
     * Start unit process
     *
     * @param string $type
     *
     * @throws \Exception
     */
    public function unit(string $type): void
    {
        //Detect env
        if (!parent::$is_CLI) {
            throw new \Exception('Run under CLI!', E_USER_ERROR);
        }

        $unit_exec = 0;

        switch ($type) {
            case 'delay':
                //Read time rec
                if (empty($time_list = $this->instance->zRangeByScore($this->key_delay_time, 0, time()))) {
                    break;
                }

                //Seek for jobs
                foreach ($time_list as $time_rec) {
                    $time_key = (string)$time_rec;

                    do {
                        //No delay job found
                        if (false === $delay_job = $this->instance->rPop($this->prefix_delay_jobs . $time_key)) {
                            $this->instance->zRem($this->key_delay_time, $time_key);
                            $this->instance->hDel($this->key_delay_lock, $time_key);
                            break;
                        }

                        $job_data = json_decode($delay_job, true);

                        //Add realtime job
                        $this->add_realtime($job_data['group'], $job_data['job']);
                    } while (false !== $delay_job && $unit_exec < $this->max_exec);

                    ++$unit_exec;
                }

                unset($time_list, $time_rec, $time_key, $delay_job, $job_data);
                break;

            default:
                //Get idle time
                $idle_time = $this->get_idle_time();

                //Build unit hash and key
                $unit_hash = hash('crc32b', uniqid(mt_rand(), true));
                $unit_key  = $this->prefix_worker . $unit_hash;

                //Add to watch list
                $this->instance->set($unit_key, '', self::WAIT_SCAN);
                $this->instance->hSet($this->get_watch_key(), $unit_key, time());

                //Close on exit
                register_shutdown_function([$this, 'close'], $unit_hash);

                do {
                    //Exit on no job
                    if (empty($list = $this->show_queue())) {
                        break;
                    }

                    //Execute job
                    if (!empty($queue = $this->instance->brPop(array_keys($list), $idle_time))) {
                        $this->exec_job($queue[1]);
                    }
                } while (0 < $this->instance->exists($unit_key) && $this->instance->expire($unit_key, self::WAIT_SCAN) && ++$unit_exec < $this->max_exec);

                //On exit
                $this->close($unit_hash);

                unset($idle_time, $unit_hash, $unit_key, $list, $queue);
                break;
        }

        unset($type, $unit_exec);
    }

    /**
     * Add realtime job
     *
     * @param string $group
     * @param string $data
     *
     * @return int
     */
    private function add_realtime(string $group, string $data): int
    {
        //Add watch list
        $this->instance->hSet($this->key_listen, $key = $this->prefix_jobs . $group, time());

        //Add job
        $result = (int)$this->instance->lPush($key, $data);

        unset($group, $data, $key);
        return $result;
    }

    /**
     * Add unique job
     *
     * @param string $group
     * @param string $data
     * @param int    $time
     * @param string $unique
     *
     * @return int
     */
    private function add_unique(string $group, string $data, int $time, string $unique): int
    {
        //Check job duration
        if (!$this->instance->setnx($key = $this->prefix_unique . $unique, time())) {
            return -1;
        }

        //Set duration life
        $this->instance->expire($key, $time);

        //Add realtime job
        $result = $this->add_realtime($group, $data);

        unset($group, $data, $time, $unique, $key);
        return $result;
    }

    /**
     * Add delay job
     *
     * @param string $group
     * @param string $data
     * @param int    $time
     *
     * @return int
     */
    private function add_delay(string $group, string $data, int $time): int
    {
        //Calculate delay time
        $delay = time() + $time;

        //Set time lock
        if ($this->instance->hSetNx($this->key_delay_lock, $delay, $delay)) {
            //Add time rec
            $this->instance->zAdd($this->key_delay_time, $delay, $delay);
        }

        //Add delay job
        $result = $this->instance->lPush(
            $this->prefix_delay_jobs . (string)$delay,
            json_encode([
                'group' => &$group,
                'job'   => &$data
            ], JSON_FORMAT));

        unset($group, $data, $time, $delay);
        return $result;
    }

    /**
     * Get active keys
     *
     * @param string $key
     *
     * @return array
     */
    private function get_keys(string $key): array
    {
        if (0 === $this->instance->exists($key)) {
            return [];
        }

        if (empty($keys = $this->instance->hGetAll($key))) {
            return [];
        }

        foreach ($keys as $k => $v) {
            if (0 === $this->instance->exists($k)) {
                $this->instance->hDel($key, $k);
                unset($keys[$k]);
            }
        }

        unset($key, $k, $v);
        return $keys;
    }

    /**
     * Get watch key
     *
     * @return string
     */
    private function get_watch_key(): string
    {
        return $this->prefix_watch . ($_SERVER['HOSTNAME'] ?? 'worker');
    }

    /**
     * Get idle time
     *
     * @return int
     */
    private function get_idle_time(): int
    {
        return (int)(self::WAIT_SCAN / 2);
    }

    /**
     * Delay unit process
     */
    private function call_unit_delay(): void
    {
        pclose(popen(platform::cmd_bg($this->unit_cmd . ' --data="type=delay"'), 'r'));
    }

    /**
     * Realtime unit process
     */
    private function call_unit_realtime(): void
    {
        //Count running processes
        $running = count($this->show_process());

        if (0 >= $left = $this->max_fork - $running + 1) {
            return;
        }

        //Read queue list
        $queue = $this->show_queue();

        //Count jobs
        $jobs = 0;
        foreach ($queue as $key => $item) {
            $jobs += $this->instance->lLen($key);
        }

        //Exit on no job
        if (0 === $jobs) {
            return;
        }

        //Count need processes
        if ($left < $need = (ceil($jobs / $this->max_exec) - $running + 1)) {
            $need = &$left;
        }

        //Call unit processes
        $cmd = platform::cmd_bg($this->unit_cmd . ' --data="type=realtime"');

        for ($i = 0; $i < $need; ++$i) {
            pclose(popen($cmd, 'r'));
        }

        unset($running, $left, $queue, $jobs, $key, $item, $need, $cmd, $i);
    }

    /**
     * Execute job
     *
     * @param string $data
     */
    private function exec_job(string $data): void
    {
        try {
            //Decode data in JSON
            if (!is_array($input = json_decode($data, true))) {
                throw new \Exception('Data ERROR!', E_USER_WARNING);
            }

            //Check command
            if (false === strpos($input['cmd'], '-')) {
                throw new \Exception('Command [' . $input['cmd'] . '] ERROR!', E_USER_WARNING);
            }

            //Job list
            $job_list = [];

            //Get order & method
            list($order, $method) = explode('-', $input['cmd'], 2);

            //Process LOAD dependency
            if (false !== strpos($module = strtr($order, '\\', '/'), '/')) {
                $module = strstr(parent::get_app_cmd($module), '/', true);
            }

            if (isset(parent::$load[$module])) {
                $dep_list = is_string(parent::$load[$module]) ? [parent::$load[$module]] : parent::$load[$module];

                //Build dependency
                parent::build_dep($dep_list);

                //Save to job list
                foreach ($dep_list as $dep) {
                    $job_list[] = [$dep[1], $dep[2]];
                }

                unset($dep_list, $dep);
            }

            //Save class & method
            $job_list[] = [parent::get_app_class($order), $method];

            //Execute jobs
            foreach ($job_list as $job) {
                //Get reflection
                $reflect = parent::reflect_method($job[0], $job[1]);

                //Check method visibility
                if (!$reflect->isPublic()) {
                    throw new \ReflectionException($job[0] . '::' . $job[1] . ': NOT for public!', E_USER_WARNING);
                }

                //Call constructor
                if ('__construct' === $job[1]) {
                    parent::obtain($job[0], data::build_argv($reflect, $input));
                    continue;
                }

                //Using class object
                if (!$reflect->isStatic()) {
                    $job[0] = method_exists($job[0], '__construct')
                        ? parent::obtain($job[0], data::build_argv(parent::reflect_method($job[0], '__construct'), $input))
                        : parent::obtain($job[0]);
                }

                //Call method (with params)
                $result = !empty($params = data::build_argv($reflect, $input))
                    ? forward_static_call_array([$job[0], $job[1]], $params)
                    : forward_static_call([$job[0], $job[1]]);

                //Check result
                $this->check_job($data, json_encode($result));
            }
        } catch (\Throwable $throwable) {
            $this->instance->lPush($this->key_failed, json_encode(['data' => &$data, 'return' => $throwable->getMessage()], JSON_FORMAT));
            unset($throwable);
            return;
        }

        unset($data, $input, $job_list, $order, $method, $module, $job, $reflect, $params, $result);
    }

    /**
     * Check job
     * Only accept null & true
     *
     * @param string $data
     * @param string $result
     */
    private function check_job(string $data, string $result): void
    {
        //Decode result
        $json = json_decode($result, true);

        //Save to fail list
        if (!is_null($json) && true !== $json) {
            $this->instance->lPush($this->key_failed, json_encode(['data' => &$data, 'return' => &$result], JSON_FORMAT));
        }

        unset($data, $result, $json);
    }
}