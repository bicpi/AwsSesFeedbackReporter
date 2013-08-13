<?php

require_once __DIR__.'/../vendor/autoload.php';

use Silex\Application;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;
use bicpi\MongoDbServiceProvider;
use Knp\Component\Pager\Paginator;

$app = new Application();
$app['parameters'] = Yaml::parse(file_get_contents(__DIR__.'/../src/parameters.yml'));
$app['debug'] = $app['parameters']['debug'];
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../src/views',
));
$app->register(new MongoDbServiceProvider(), array());
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
$app['accessor'] = PropertyAccess::createPropertyAccessor();

$app->error(function (\Exception $e, $code) use ($app) {
    if ($app['debug']) {
        return;
    }

    switch ($code) {
        case 404:
            return $app['twig']->render('error.html.twig', array(
                'message' => 'Error 404: File not found'
            ));
    }

    return $app['twig']->render('error.html.twig', array(
        'message' => 'Error 500: Internal server error'
    ));
});

$app->get('/', function (Request $request) use ($app) {
    $filter = array_filter($request->query->get('filter', array()));
    $notifications = $app['mongodb']->$app['parameters']['dbname']->notifications
        ->find(array_map(function($val){ return array('$regex' => preg_quote($val));}, $filter))
        ->sort(array('timestamp' => -1));

    if ($export = $request->query->get('_export')) {
        return export($export, $notifications);
    }

    $page = $request->query->get('page', 1);
    $paginator = new Paginator();
    $paginator->subscribe(new \bicpi\MongoDbSubscriber());
    $pagination = $paginator->paginate($notifications, $page, $app['parameters']['limit_per_page']);

    return $app['twig']->render('notifications.html.twig', array(
        'page' => $page,
        'filter' => $filter,
        'pagination' => $pagination
    ));
})->bind('notifications');

$app->post('/', function (Request $request) use ($app) {
    $notification = json_decode($request->getContent(), true);

    if ('SubscriptionConfirmation' == $app['accessor']->getValue($notification, '[Type]')) {
        file_put_contents(__DIR__.'/../logs/app.log', $request->getContent() . "\n");

        return new Response('', 201);
    }

    $notification['raw'] = $request->getContent();
    $message = json_decode($app['accessor']->getValue($notification, '[Message]'), true);
    if ($message) {
        switch ($app['accessor']->getValue($message, '[notificationType]')) {
            case 'Bounce':
                return createBounce($message);
            case 'Complaint':
                return createComplaint($message);
        }
    }

    return new Response('', 503);
});

$app->match('/post-raw', function (Request $request) use ($app) {
    if ('POST' == $request->getMethod()) {
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, $request->getSchemeAndHttpHost());
        curl_setopt($ch, CURLOPT_POST, 1)   ;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request->request->get('raw'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: text/plain',
            'Content-Length: ' . strlen($request->request->get('raw')))
        );
        $result = curl_exec($ch);
        curl_close($ch);

        return $app->redirect('/');
    }

    return $app['twig']->render('post-raw.html.twig');
})
->method('GET|POST')
->bind('postRaw');

$app->run();


function export($exportType, $bounces)
{
    $csv = array();
    switch ($exportType) {
        case 'recipients_only':
            $filename = '%s_bounces_recipients_only.csv';
            foreach ($bounces as $bounce) {
                $line = array();
                $line[] = $bounce['bounce_recipient'];
                $csv[] = implode(',', $line);
            }
            break;
        case 'detailed':
            $filename = '%s_bounces_detailed.csv';
            $csv[] = 'Bounced recipient,Bounced at,Bounce type,Bounce subtype,Bounce message,Origin sendout at,Sendout return path';
            foreach ($bounces as $bounce) {
                $line = array();
                $line[] = $bounce['bounce_recipient'];
                $line[] = $bounce['bounce_timestamp'];
                $line[] = $bounce['bounce_type'];
                $line[] = $bounce['bounce_subType'];
                $line[] = $bounce['bounce_diagnosticCode'];
                $line[] = $bounce['mail_timestamp'];
                $line[] = $bounce['mail_source'];
                $line = array_map(function($val){ return sprintf('"%s"', str_replace('"', '\"', $val)); }, $line);
                $csv[] = implode(',', $line);
            }
            break;
        default:
            throw new \Exception('Export type not implemented.');
    }

    $content = implode("\n", $csv);
    $filename = sprintf($filename, date('Y-m-d_H-i-s'));

    return new Response($content, 200, array(
        'Content-Type' => 'text/csv;charset=utf-8',
        'Content-Length' => strlen($content),
        'Content-Disposition' => sprintf('attachment; filename="%s"', urlencode($filename)),
    ));
};

function createBounce($message)
{
    global $app;

    $accessor = $app['accessor'];
    foreach ($accessor->getValue($message, '[bounce][bouncedRecipients]') as $bouncedRecipient) {
        $app['mongodb']->$app['parameters']['dbname']->notifications->insert(array(
            'notificationType' => $accessor->getValue($message, '[notificationType]'),
            'raw' => $app['request']->getContent(),
            'type' => $accessor->getValue($message, '[bounce][bounceType]'),
            'subType' => $accessor->getValue($message, '[bounce][bounceSubType]'),
            'reportingMTA' => $accessor->getValue($message, '[bounce][reportingMTA]'),
            'recipient' => $accessor->getValue($bouncedRecipient, '[emailAddress]'),
            'status' => $accessor->getValue($bouncedRecipient, '[status]'),
            'action' => $accessor->getValue($bouncedRecipient, '[action]'),
            'diagnosticCode' => $accessor->getValue($bouncedRecipient, '[diagnosticCode]'),
            'timestamp' => $accessor->getValue($message, '[bounce][timestamp]'),
            'feedbackId' => $accessor->getValue($message, '[bounce][feedbackId]'),
            'mail_timestamp' => $accessor->getValue($message, '[mail][timestamp]'),
            'mail_messageId' => $accessor->getValue($message, '[mail][messageId]'),
            'mail_source' => $accessor->getValue($message, '[mail][source]'),
            'mail_destination' => implode(', ', $accessor->getValue($message, '[mail][destination]')),
        ));
    }

    return new Response('', 201);
}

function createComplaint($message)
{
    global $app;

    $accessor = $app['accessor'];
    foreach ($accessor->getValue($message, '[complaint][complainedRecipients]') as $complainedRecipient) {
        $app['mongodb']->$app['parameters']['dbname']->notifications->insert(array(
            'notificationType' => $accessor->getValue($message, '[notificationType]'),
            'raw' => $app['request']->getContent(),
            'recipient' => $accessor->getValue($complainedRecipient, '[emailAddress]'),
            'userAgent' => $accessor->getValue($message, '[complaint][userAgent]'),
            'complaintFeedbackType' => $accessor->getValue($message, '[complaint][complaintFeedbackType]'),
            'arrivalDate' => $accessor->getValue($message, '[complaint][arrivalDate]'),
            'timestamp' => $accessor->getValue($message, '[complaint][timestamp]'),
            'feedbackId' => $accessor->getValue($message, '[complaint][feedbackId]'),
            'mail_timestamp' => $accessor->getValue($message, '[mail][timestamp]'),
            'mail_messageId' => $accessor->getValue($message, '[mail][messageId]'),
            'mail_source' => $accessor->getValue($message, '[mail][source]'),
            'mail_destination' => implode(', ', $accessor->getValue($message, '[mail][destination]')),
        ));
    }

    return new Response('', 201);
}
