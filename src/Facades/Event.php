<?php

namespace Hybrid\Events\Facades;

use Hybrid\Core\Facades\Facade;

/**
 * @see \Hybrid\Events\Dispatcher
 *
 * @method static \Closure createClassListener(string $listener, bool $wildcard = false)
 * @method static \Closure makeListener(\Closure|string $listener, bool $wildcard = false)
 * @method static array getListeners(string $eventName)
 * @method static array getRawListeners()
 * @method static array|null dispatch(string|object $event, mixed $payload = [], bool $halt = false)
 * @method static array|null until(string|object $event, mixed $payload = [])
 * @method static bool hasListeners(string $eventName)
 * @method static void flush(string $event)
 * @method static void forget(string $event)
 * @method static void forgetPushed()
 * @method static void listen(\Closure|string|array $events, \Closure|string|array $listener = null)
 * @method static void push(string $event, object|array $payload = [])
 * @method static void subscribe(object|string $subscriber)
 */
class Event extends Facade {

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor() {
        return 'events';
    }

}
