<?php

namespace bicpi;

use Silex\ServiceProviderInterface;
use Silex\Application;
use Symfony\Component\PropertyAccess\PropertyAccess;

class MongoDbServiceProvider implements ServiceProviderInterface
{
    protected $app;

    public function register(Application $app)
    {
        $this->app = $app;
        $app['mongodb'] = $app->share(function () use ($app) {
            $options = array();
            if ($app['parameters']['dbuser']) {
                $options['username'] = $app['parameters']['dbuser'];
                $options['password'] = $app['parameters']['dbpassword'];
                $options['db'] = $app['parameters']['dbname'];
            }

            $mongo = new \MongoClient(null, $options);

            return $mongo->$app['parameters']['dbname'];
        });
    }

    public function boot(Application $app)
    {
    }
}