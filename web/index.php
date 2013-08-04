<?php

require_once __DIR__.'/../vendor/autoload.php';

use Silex\Application;
use Silex\Provider\DoctrineServiceProvider;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;

$parameters = Yaml::parse(file_get_contents(__DIR__.'/../src/parameters.yml'));

$app = new Application();
$app['debug'] = $parameters['debug'];

$app->register(new DoctrineServiceProvider, array(
    'db.options' => array(
        'driver'    => 'pdo_mysql',
        'dbname'    => $parameters['dbname'],
        'user'      => $parameters['dbuser'],
        'password'  => $parameters['dbpassword'],
    )
));

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../src/views',
));


$app->error(function (\Exception $e, $code) use ($app) {
    if ($app['debug']) {
        return;
    }

    switch ($code) {
        case 404:
            return $app['twig']->render('error.html.twig', array(
                'message' => 'Error 404: File not found'
            ));
            break;
        default:
            return $app['twig']->render('error.html.twig', array(
                'message' => 'Error 500: Internal server error'
            ));
    }

    return new Response($message, $code);
});

$app->get('/', function () use ($app) {
    $sql = "SELECT * FROM bounce";
    $bounces = $app['db']->fetchAll($sql);

    return $app['twig']->render('bounces.html.twig', array(
        'bounces' => $bounces
    ));
});

$app->post('/', function (Request $request) use ($app) {
    $notification = json_decode($request->getContent(), true);
    $request->request->replace(is_array($notification) ? $notification : array());

    $accessor = PropertyAccess::createPropertyAccessor();

    $entity = array(
        'content_type' => $request->getContentType(),
        'raw' => $request->getContent(),
    );

    switch ($accessor->getValue($notification, '[Type]')) {
        case 'SubscriptionConfirmation':
            $app['db']->insert('subscription_confirmation', $entity);
            break;
        case 'Notification':
            $message = $accessor->getValue($notification, '[Message]');
            file_put_contents('output.txt', print_r($message, 1));
            return;
            switch ($accessor->getValue($message, '[notificationType]')) {
                case 'Bounce':
                    $entity = $entity + array(
                        'type' => $accessor->getValue($message, '[bounce][bounceType]'),
                        'sub_type' => $accessor->getValue($message, '[bounce][bounceSubType]'),
                    );
                    foreach ($accessor->getValue($message, '[bounce][boundedRecipients]') as $recipient) {
                        $bounce = $entity + array(
                                'emailAddress' => $accessor->getValue($$recipient, '[emailAddress]'),
                                'status' => $accessor->getValue($$recipient, '[status]'),
                                'action' => $accessor->getValue($$recipient, '[action]'),
                            );
                        $app['db']->insert('bounce', $bounce);
                    }
                    break;
                case 'Complaint':
                    $app['db']->insert('complaint', $entity);
                    break;
                default:
                    // Not implemented
                    return new Response('', 501);
            }
            break;
    }

    return new Response('', 201);
});

$app->match('/post-raw', function (Request $request) use ($app) {
    if ('POST' == $request->getMethod()) {
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, $request->getSchemeAndHttpHost());
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request->request->get('raw'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: '.$request->request->get('content-type'),
            'Content-Length: ' . strlen($request->request->get('raw')))
        );
        curl_exec($ch);
        curl_close($ch);

        return $app->redirect('/');
    }

    return $app['twig']->render('post-raw.html.twig');
})
->method('GET|POST');

$app->run();
