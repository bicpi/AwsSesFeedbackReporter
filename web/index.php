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
use Symfony\Component\Security\Core\Encoder\MessageDigestPasswordEncoder;

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

$encoder = new MessageDigestPasswordEncoder();
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
                'admin' => array('ROLE_ADMIN', $encoder->encodePassword($app['parameters']['users']['admin'], '')),
                'superadmin' => array('ROLE_SUPERADMIN', $encoder->encodePassword($app['parameters']['users']['superadmin'], '')),
            ),
        ),
    ),
    'security.role_hierarchy' => array(
        'ROLE_SUPERADMIN' => array('ROLE_ADMIN'),
    ),
    'security.access_rules' => array(
        array('^/$', 'IS_AUTHENTICATED_ANONYMOUSLY'),
        array('^/admin/notifications', 'ROLE_ADMIN'),
        array('^/admin', 'ROLE_SUPERADMIN'),
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
