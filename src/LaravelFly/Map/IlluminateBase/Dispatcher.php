<?php
/**
 * 1. Dict,
 * 2. Cache for event listeners $listenersCache
 *      It is across multple requests,
 *      Changes in any request would change this var
 *          static $listenersStalbe = [];
 *      This cache will not have performance effect when Wildcard listeners are added in requests, because :
 *          static::$wildStable
 *
 * 3. swoole event (delivered to swoole task, just as swoole job)
 *
 */

namespace LaravelFly\Map\IlluminateBase;

use Exception;
use ReflectionClass;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Container\Container as ContainerContract;

class Dispatcher extends \Illuminate\Events\Dispatcher
{

    use \LaravelFly\Map\Util\Dict;

    protected static $normalAttriForObj = ['queueResolver' => null];

    protected static $arrayAttriForObj = ['listeners', 'wildcards', 'wildcardsCache'];

    public function __construct(ContainerContract $container)
    {
        $this->container = $container;
        $this->initOnWorker(false);
    }

    public function listen($events, $listener)
    {
        foreach ((array)$events as $event) {
            if (Str::contains($event, '*')) {
                $this->setupWildcardListen($event, $listener);
            } else {
                static::$listenersStalbe[$event] = false;
                static::$corDict[\Co::getUid()]['listeners'][$event][] = $this->makeListener($listener);
            }
        }
    }

    protected function setupWildcardListen($event, $listener)
    {
        static::$corDict[\Co::getUid()]['wildcards'][$event][] = $this->makeListener($listener, true);

        static::$corDict[\Co::getUid()]['wildcardsCache'] = [];

        // hack: Cache for event listeners
        static::$wildStable = false;
    }

    public function hasListeners($eventName)
    {
        $current = static::$corDict[\Co::getUid()];
        return isset($current['listeners'][$eventName]) || isset($current['wildcards'][$eventName]);
    }

    // hack: Cache for event listeners
    static $listenersStalbe = [];
    static $wildStable = false;

    public function getListeners($eventName)
    {
        // hack: Cache for event listeners
        static $listenersCache = [], $used = 0;
        if (static::$wildStable && !empty(static::$listenersStalbe[$eventName])) {
            ++$used;
            return $listenersCache[$eventName];
        }
        static::$listenersStalbe[$eventName] = true;
        static::$wildStable = true;

        $listeners = static::$corDict[\Co::getUid()]['listeners'][$eventName] ?? [];

        $listeners = array_merge(
            $listeners,
            static::$corDict[\Co::getUid()]['wildcardsCache'][$eventName] ?? $this->getWildcardListeners($eventName)
        );

        // hack: Cache for event listeners
        return $listenersCache[$eventName] = class_exists($eventName, false)
            ? $this->addInterfaceListeners($eventName, $listeners)
            : $listeners;
    }

    protected function getWildcardListeners($eventName)
    {
        $wildcards = [];

        foreach (static::$corDict[\Co::getUid()]['wildcards'] as $key => $listeners) {
            if (Str::is($key, $eventName)) {
                $wildcards = array_merge($wildcards, $listeners);
            }
        }

        return static::$corDict[\Co::getUid()]['wildcardsCache'][$eventName] = $wildcards;
    }

    protected function addInterfaceListeners($eventName, array $listeners = [])
    {
        $current = static::$corDict[\Co::getUid()]['listeners'];

        foreach (class_implements($eventName) as $interface) {
            if (isset($current[$interface])) {
                foreach ($current[$interface] as $names) {
                    $listeners = array_merge($listeners, (array)$names);
                }
            }
        }

        return $listeners;
    }

    public function forget($event)
    {
        $cid = \Co::getUid();
        if (Str::contains($event, '*')) {
            unset(static::$corDict[$cid]['wildcards'][$event]);

            // hack: Cache for event listeners
            static::$wildStable = false;

        } else {
            unset(static::$corDict[$cid]['listeners'][$event]);

            // hack: Cache for event listeners
            static::$listenersStalbe[$event] = false;
        }
    }

    public function forgetPushed()
    {
        foreach (static::$corDict[\Co::getUid()]['listeners'] as $key => $value) {
            if (Str::endsWith($key, '_pushed')) {
                $this->forget($key);
            }
        }

    }

    protected function resolveQueue()
    {
        return call_user_func(static::$corDict[\Co::getUid()]['queueResolver']);
    }

    public function setQueueResolver(callable $resolver)
    {
        static::$corDict[\Co::getUid()]['queueResolver'] = $resolver;

        return $this;
    }


    // following about swoole event

    /**
     * @var \swoole_http_server
     */
    static $swooleServer;

    static $swooleListeners = [];

    protected function createClassCallable($listener)
    {
        [$class, $method] = $this->parseClassCallable($listener);


        if (in_array($class, static::$swooleListeners)) {
            return $this->createSwooleQueuedHandlerCallable($class, $method);
        }

        if ($this->handlerShouldBeQueued($class)) {
            return $this->createQueuedHandlerCallable($class, $method);
        }

        return [$this->container->make($class), $method];
    }

    protected function createSwooleQueuedHandlerCallable($class, $method)
    {
        return function () use ($class, $method) {
            $arguments = array_map(function ($a) {
                return is_object($a) ? clone $a : $a;
            }, func_get_args());

            static::$swooleServer->task([
                'type' => 'event',
                'object' => $this->container->make($class),
                'data' => $arguments,
            ]);
        };
    }

}
