<?php

namespace Hybrid\Events;

use Closure;
use Hybrid\Container\Container;
use Hybrid\Contracts\Container\Container as ContainerContract;
use Hybrid\Contracts\Events\Dispatcher as DispatcherContract;
use Hybrid\Tools\Arr;
use Hybrid\Tools\Str;
use Hybrid\Tools\Traits\Macroable;
use Hybrid\Tools\Traits\ReflectsClosures;
use function Hybrid\Tools\collect;

class Dispatcher implements DispatcherContract {

    use Macroable;
    use ReflectsClosures;

    /**
     * The IoC container instance.
     *
     * @var \Hybrid\Contracts\Container\Container
     */
    protected $container;

    /**
     * The registered event listeners.
     *
     * @var array
     */
    protected $listeners = [];

    /**
     * The wildcard listeners.
     *
     * @var array
     */
    protected $wildcards = [];

    /**
     * The cached wildcard listeners.
     *
     * @var array
     */
    protected $wildcardsCache = [];

    /**
     * Create a new event dispatcher instance.
     *
     * @return void
     */
    public function __construct( ?ContainerContract $container = null ) {
        $this->container = $container ?: new Container();
    }

    /**
     * Register an event listener with the dispatcher.
     *
     * @param  \Closure|string|array      $events
     * @param  \Closure|string|array|null $listener
     * @return void
     */
    public function listen( $events, $listener = null ) {
        if ( $events instanceof Closure ) {
            return collect( $this->firstClosureParameterTypes( $events ) )
                ->each(function ( $event ) use ( $events ) {
                    $this->listen( $event, $events );
                });
        }

        foreach ( (array) $events as $event ) {
            if ( str_contains( $event, '*' ) ) {
                $this->setupWildcardListen( $event, $listener );
            } else {
                $this->listeners[ $event ][] = $listener;
            }
        }
    }

    /**
     * Setup a wildcard listener callback.
     *
     * @param  string          $event
     * @param  \Closure|string $listener
     * @return void
     */
    protected function setupWildcardListen( $event, $listener ) {
        $this->wildcards[ $event ][] = $listener;

        $this->wildcardsCache = [];
    }

    /**
     * Determine if a given event has listeners.
     *
     * @param  string $eventName
     * @return bool
     */
    public function hasListeners( $eventName ) {
        return isset( $this->listeners[ $eventName ] ) ||
                isset( $this->wildcards[ $eventName ] ) ||
                $this->hasWildcardListeners( $eventName );
    }

    /**
     * Determine if the given event has any wildcard listeners.
     *
     * @param  string $eventName
     * @return bool
     */
    public function hasWildcardListeners( $eventName ) {
        foreach ( $this->wildcards as $key => $listeners ) {
            if ( Str::is( $key, $eventName ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register an event and payload to be fired later.
     *
     * @param  string       $event
     * @param  object|array $payload
     * @return void
     */
    public function push( $event, $payload = [] ) {
        $this->listen($event . '_pushed', function () use ( $event, $payload ) {
            $this->dispatch( $event, $payload );
        });
    }

    /**
     * Flush a set of pushed events.
     *
     * @param  string $event
     * @return void
     */
    public function flush( $event ) {
        $this->dispatch( $event . '_pushed' );
    }

    /**
     * Register an event subscriber with the dispatcher.
     *
     * @param  object|string $subscriber
     * @return void
     */
    public function subscribe( $subscriber ) {
        $subscriber = $this->resolveSubscriber( $subscriber );

        $events = $subscriber->subscribe( $this );

        if ( is_array( $events ) ) {
            foreach ( $events as $event => $listeners ) {
                foreach ( Arr::wrap( $listeners ) as $listener ) {
                    if ( is_string( $listener ) && method_exists( $subscriber, $listener ) ) {
                        $this->listen( $event, [ get_class( $subscriber ), $listener ] );

                        continue;
                    }

                    $this->listen( $event, $listener );
                }
            }
        }
    }

    /**
     * Resolve the subscriber instance.
     *
     * @param  object|string $subscriber
     * @return mixed
     */
    protected function resolveSubscriber( $subscriber ) {
        if ( is_string( $subscriber ) ) {
            return $this->container->make( $subscriber );
        }

        return $subscriber;
    }

    /**
     * Fire an event until the first non-null response is returned.
     *
     * @param  string|object $event
     * @param  mixed         $payload
     * @return mixed
     */
    public function until( $event, $payload = [] ) {
        return $this->dispatch( $event, $payload, true );
    }

    /**
     * Fire an event and call the listeners.
     *
     * @param  string|object $event
     * @param  mixed         $payload
     * @param  bool          $halt
     * @return array|null
     */
    public function dispatch( $event, $payload = [], $halt = false ) {
        // When the given "event" is actually an object we will assume it is an event
        // object and use the class as the event name and this event itself as the
        // payload to the handler, which makes object based events quite simple.
        [$event, $payload] = $this->parseEventAndPayload( $event, $payload );

        $responses = [];

        foreach ( $this->getListeners( $event ) as $listener ) {
            $response = $listener( $event, $payload );

            // If a response is returned from the listener and event halting is enabled
            // we will just return this response, and not call the rest of the event
            // listeners. Otherwise we will add the response on the response list.
            if ( $halt && ! is_null( $response ) ) {
                return $response;
            }

            // If a boolean false is returned from a listener, we will stop propagating
            // the event to any further listeners down in the chain, else we keep on
            // looping through the listeners and firing every one in our sequence.
            if ( $response === false ) {
                break;
            }

            $responses[] = $response;
        }

        return $halt ? null : $responses;
    }

    /**
     * Parse the given event and payload and prepare them for dispatching.
     *
     * @param  mixed $event
     * @param  mixed $payload
     * @return array
     */
    protected function parseEventAndPayload( $event, $payload ) {
        if ( is_object( $event ) ) {
            [$payload, $event] = [ [ $event ], get_class( $event ) ];
        }

        return [ $event, Arr::wrap( $payload ) ];
    }

    /**
     * Get all of the listeners for a given event name.
     *
     * @param  string $eventName
     * @return array
     */
    public function getListeners( $eventName ) {
        $listeners = array_merge(
            $this->prepareListeners( $eventName ),
            $this->wildcardsCache[ $eventName ] ?? $this->getWildcardListeners( $eventName )
        );

        return class_exists( $eventName, false )
                    ? $this->addInterfaceListeners( $eventName, $listeners )
                    : $listeners;
    }

    /**
     * Get the wildcard listeners for the event.
     *
     * @param  string $eventName
     * @return array
     */
    protected function getWildcardListeners( $eventName ) {
        $wildcards = [];

        foreach ( $this->wildcards as $key => $listeners ) {
            if ( Str::is( $key, $eventName ) ) {
                foreach ( $listeners as $listener ) {
                    $wildcards[] = $this->makeListener( $listener, true );
                }
            }
        }

        return $this->wildcardsCache[ $eventName ] = $wildcards;
    }

    /**
     * Add the listeners for the event's interfaces to the given array.
     *
     * @param  string $eventName
     * @param  array  $listeners
     * @return array
     */
    protected function addInterfaceListeners( $eventName, array $listeners = [] ) {
        foreach ( class_implements( $eventName ) as $interface ) {
            if ( isset( $this->listeners[ $interface ] ) ) {
                foreach ( $this->prepareListeners( $interface ) as $names ) {
                    $listeners = array_merge( $listeners, (array) $names );
                }
            }
        }

        return $listeners;
    }

    /**
     * Prepare the listeners for a given event.
     *
     * @return array<\Closure>
     */
    protected function prepareListeners( string $eventName ) {
        $listeners = [];

        foreach ( $this->listeners[ $eventName ] ?? [] as $listener ) {
            $listeners[] = $this->makeListener( $listener );
        }

        return $listeners;
    }

    /**
     * Register an event listener with the dispatcher.
     *
     * @param  \Closure|string|array $listener
     * @param  bool                  $wildcard
     * @return \Closure
     */
    public function makeListener( $listener, $wildcard = false ) {
        if ( is_string( $listener ) ) {
            return $this->createClassListener( $listener, $wildcard );
        }

        if ( is_array( $listener ) && isset( $listener[0] ) && is_string( $listener[0] ) ) {
            return $this->createClassListener( $listener, $wildcard );
        }

        return static function ( $event, $payload ) use ( $listener, $wildcard ) {
            if ( $wildcard ) {
                return $listener( $event, $payload );
            }

            return $listener( ...array_values( $payload ) );
        };
    }

    /**
     * Create a class based listener using the IoC container.
     *
     * @param  string $listener
     * @param  bool   $wildcard
     * @return \Closure
     */
    public function createClassListener( $listener, $wildcard = false ) {
        return function ( $event, $payload ) use ( $listener, $wildcard ) {
            if ( $wildcard ) {
                return call_user_func( $this->createClassCallable( $listener ), $event, $payload );
            }

            $callable = $this->createClassCallable( $listener );

            return $callable( ...array_values( $payload ) );
        };
    }

    /**
     * Create the class based event callable.
     *
     * @param  array|string $listener
     * @return callable
     */
    protected function createClassCallable( $listener ) {
        [$class, $method] = is_array( $listener )
                            ? $listener
                            : $this->parseClassCallable( $listener );

        if ( ! method_exists( $class, $method ) ) {
            $method = '__invoke';
        }

        $listener = $this->container->make( $class );

        return $this->handlerShouldBeDispatchedAfterDatabaseTransactions( $listener )
                    ? $this->createCallbackForListenerRunningAfterCommits( $listener, $method )
                    : [ $listener, $method ];
    }

    /**
     * Parse the class listener into class and method.
     *
     * @param  string $listener
     * @return array
     */
    protected function parseClassCallable( $listener ) {
        return Str::parseCallback( $listener, 'handle' );
    }

    /**
     * Determine if the given event handler should be dispatched after all database transactions have committed.
     *
     * @param  object|mixed $listener
     * @return bool
     */
    protected function handlerShouldBeDispatchedAfterDatabaseTransactions( $listener ) {
        return ( $listener->afterCommit ?? null ) && $this->container->bound( 'db.transactions' );
    }

    /**
     * Create a callable for dispatching a listener after database transactions.
     *
     * @param  mixed  $listener
     * @param  string $method
     * @return \Closure
     */
    protected function createCallbackForListenerRunningAfterCommits( $listener, $method ) {
        return function () use ( $method, $listener ) {
            $payload = func_get_args();

            $this->container->make( 'db.transactions' )->addCallback(
                static function () use ( $listener, $method, $payload ) {
                    $listener->$method( ...$payload );
                }
            );
        };
    }

    /**
     * Remove a set of listeners from the dispatcher.
     *
     * @param  string $event
     * @return void
     */
    public function forget( $event ) {
        if ( str_contains( $event, '*' ) ) {
            unset( $this->wildcards[ $event ] );
        } else {
            unset( $this->listeners[ $event ] );
        }

        foreach ( $this->wildcardsCache as $key => $listeners ) {
            if ( Str::is( $event, $key ) ) {
                unset( $this->wildcardsCache[ $key ] );
            }
        }
    }

    /**
     * Forget all of the pushed listeners.
     *
     * @return void
     */
    public function forgetPushed() {
        foreach ( $this->listeners as $key => $value ) {
            if ( str_ends_with( $key, '_pushed' ) ) {
                $this->forget( $key );
            }
        }
    }

    /**
     * Gets the raw, unprepared listeners.
     *
     * @return array
     */
    public function getRawListeners() {
        return $this->listeners;
    }

}
