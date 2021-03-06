<?php

namespace LaravelFly\Map\Illuminate\Redis;

use LaravelFly\Map\Illuminate\Database\ConnectionsTrait;
use LaravelFly\Map\Illuminate\Redis\Connection\EnsureConnected;

class RedisManager extends \Illuminate\Redis\RedisManager
{
    use ConnectionsTrait;

    /**
     *
     * [
     *      cid => [name1 => conn1, name2 => conn2 ]
     * ]
     *
     * @var array $connections
     */
    protected $connections = [];


    public function __construct($app,$driver, array $config)
    {
        parent::__construct($app,$driver, $config);

        $redis_config = app('config')['database.redis'];

        unset($redis_config['options'], $redis_config['client']);

        $this->initConnections($redis_config,'redis');

    }

    /**
     * first ensureConnected then put back
     *
     * @param $cid
     */
    function putBack($cid)
    {

        foreach ($this->connections[$cid] as $name => $conn) {
            /**
             * swooleredis or predis has no auto-reconnect
             * @var EnsureConnected $conn
             */
            $conn->ensureConnected();
            $this->pools[$name]->return($conn);
        }
    }

    public function connection($name = null)
    {
        $name = $name ?: 'default';

        $cid = \Swoole\Coroutine::getuid();

        if (!isset($this->connections[$cid][$name])) {

            return $this->connections[$cid][$name] = $this->pools[$name]->borrow();
        }

        return $this->connections[$cid][$name];

    }

    function makeConnectionForPool($name)
    {
        return $this->resolve($name);
    }


    public function connections()
    {
        return $this->connections[\Swoole\Coroutine::getuid()];
    }


    protected function connector()
    {
        switch ($this->driver) {
            case 'predis':
                return new Connector\PredisConnector();
            case 'phpredis':
                return new Connector\PhpRedisConnector();
            case 'swoole-redis':
                return new Connector\SwooleRedisConnector();

        }
    }
}