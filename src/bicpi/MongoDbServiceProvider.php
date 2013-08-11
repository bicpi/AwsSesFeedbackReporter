<?php

namespace bicpi;

use \Silex\ServiceProviderInterface;
use Silex\Application;
use Symfony\Component\PropertyAccess\PropertyAccess;

class MongoDbServiceProvider implements ServiceProviderInterface
{
    protected $app;

    public function register(Application $app)
    {
        $this->app = $app;
        $app['mongodb'] = $app->share(function () use ($app) {
            $mongo = new \MongoClient();

            return $mongo;
        });
    }

    public function boot(Application $app)
    {
    }
}