<?php

namespace LaravelFly\Greedy;

use Illuminate\Events\EventServiceProvider;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Filesystem\Filesystem;

use LaravelFly\Greedy\Routing\RoutingServiceProvider;
use LaravelFly\ProviderRepository;

class Application extends \LaravelFly\Application
{

    protected $bootedOnWorker = false;

    protected $providers_to_boot_in_worker = [];

    public function setProvidersToBootInWorker($ps)
    {
        $this->providers_to_boot_in_worker = $ps;
    }

    public function registerConfiguredProvidersBootInWorker()
    {
        (new ProviderRepository($this, new Filesystem, $this->getCachedServicesPathBootInWorker()))
            ->load($this->providers_to_boot_in_worker);
    }

    public function getCachedServicesPathBootInWorker()
    {
        return $this->bootstrapPath() . '/cache/laravelfly_services_in_worker.json';
    }

    public function resetServiceProviders()
    {
        $this->serviceProviders = [];
    }

    public function registerProvidersAcross()
    {
        $config = $this->make('config');
        $providers = array_diff(
        // providers in request have remove from 'app.providers'
            $config->get('app.providers'),
            $this->providers_to_boot_in_worker
        );

        if ($providers) {
            if ($config->get('app.debug')) {
                echo PHP_EOL, 'Providers across ( reg on work and boot on request )', PHP_EOL, __CLASS__, PHP_EOL;
                var_dump($providers);
            }

            (new ProviderRepository($this, new Filesystem, $this->getCachedServicesPathAcross()))
                ->load($providers);

        }
    }

    public function getCachedServicesPathAcross()
    {
        return $this->bootstrapPath() . '/cache/laravelfly_services_across.json';
    }

    public function bootOnWorker()
    {
        if ($this->bootedOnWorker) {
            return;
        }

        $this->fireAppCallbacks($this->bootingCallbacks);

        array_walk($this->serviceProviders, function ($p) {
            $this->bootProvider($p);
        });

        $this->bootedOnWorker = true;

        //todo it should be changed
        $this->fireAppCallbacks($this->bootedCallbacks);
    }


    /*
     * Override
     * only for compiled all routes which are made before request
     */
    protected function registerBaseServiceProviders()
    {
        $this->register(new EventServiceProvider($this));

        $this->register(new LogServiceProvider($this));

        $this->register(new RoutingServiceProvider($this));
    }
}