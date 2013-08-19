<?php

require_once __DIR__.'/../vendor/autoload.php';

use Silex\Application;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;
use bicpi\MongoDbServiceProvider;
use bicpi\PublicControllerProvider;
use bicpi\AdminControllerProvider;

$app = new Application();

$app['parameters'] = Yaml::parse(file_get_contents(__DIR__.'/../src/parameters.yml'));
$app['debug'] = $app['parameters']['debug'];
$app['accessor'] = PropertyAccess::createPropertyAccessor();

$app->register(new Silex\Provider\SessionServiceProvider());
$app->register(new Silex\Provider\ValidatorServiceProvider());
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../src/views',
));
$app->register(new MongoDbServiceProvider(), array());
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
$app->register(new Silex\Provider\SecurityServiceProvider(), array(
    'security.firewalls' => array(
        'backend' => array(
            'pattern' => '^/',
            'anonymous' => true,
            'form' => array(
                'login_path' => '/login',
                'check_path' => '/admin/login_check'
            ),
            'logout' => array(
                'logout_path' => '/admin/logout'
            ),
            'users' => array(
                'onemedia' => array('ROLE_ONEMEDIA', 'GCxwA99MnR0quW7//L8bGoDa9bvJuhTQ07pN0mC3zMk7XgCGKMxLUO+L6FQKgjFMRcXdsSjTmYTPeT7VBgTsFQ=='),
                'admin' => array('ROLE_ADMIN', '6sgg49Cz8sTsqAGm/EdbpH/aEklyKtHY2A0hNa/gH89lWODQ1s1JKJFAjnRtwuwXDFNIwOyGdD0PPTwgxzhUSA=='),
            ),
        ),
    ),
    'security.role_hierarchy' => array(
        'ROLE_ADMIN' => array('ROLE_ONEMEDIA'),
    ),
    'security.access_rules' => array(
        array('^/$', 'IS_AUTHENTICATED_ANONYMOUSLY'),
        array('^/admin/notifications', 'ROLE_ONEMEDIA'),
        array('^/admin', 'ROLE_ADMIN'),
    )
));

$app->error(function (\Exception $e, $code) use ($app) {
    if ($app['debug']) {
        return;
    }

    switch ($code) {
        case 403:
            $message = 'You do not have the appropriate permissions to access this page';
            break;
        case 404:
            $message = 'Page not found';
            break;
        case 500:
            $message = 'Internal server error';
            break;
        default:
            $message = $e->getMessage();
    }

    return $app['twig']->render('error.html.twig', array(
        'code' => $code,
        'message' => $message
    ));
});

$app->mount('/admin', new AdminControllerProvider());
$app->mount('/', new PublicControllerProvider());

$app->run();


function applog($message)
{
    file_put_contents(__DIR__.'/../logs/app.log', $message . "\n", FILE_APPEND);
}
