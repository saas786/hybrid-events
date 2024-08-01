<?php

namespace Hybrid\Events;

use Hybrid\Core\ServiceProvider;

class Provider extends ServiceProvider {

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register() {
        $this->app->singleton( 'events', static fn( $app ) => new Dispatcher( $app ) );
    }

}
