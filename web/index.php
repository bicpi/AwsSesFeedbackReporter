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

$app->get('/', function (Request $request) use ($app) {
    $filter = array_filter($request->query->get('filter', array()));
    $bounces = $app['mongodb']->$app['parameters']['dbname']->bounces
        ->find(array_map(function($val){ return array('$regex' => preg_quote($val));}, $filter))
        ->sort(array('bounce_timestamp' => -1));

    if ($export = $request->query->get('_export')) {
        $filename = '%s_bounces.csv';
        $csv = array();
        if ('recipients_only' == $export) {
            $filename = '%s_bounces_recipients_only.csv';
            foreach ($bounces as $bounce) {
                $line = array();
                $line[] = $bounce['bounce_recipient'];
                $csv[] = implode(',', $line);
            }
        } else if ('detailed' == $export) {
            $filename = '%s_bounces_detailed.csv';
            $csv[] = 'Bounced recipient,Bounced at,Bounce type,Bounce message,Origin sendout at,Sendout return path';
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
        } else {
            throw new \Exception('Export type not implemented.');
        }
        $content = implode("\n", $csv);
        $filename = sprintf($filename, date('Y-m-d_H-i-s'));

        return new Response($content, 200, array(
           'Content-Type' => 'text/csv;charset=utf-8',
           'Content-Length' => strlen($content),
           'Content-Disposition' => sprintf('attachment; filename="%s"', urlencode($filename)),
       ));
    }

    $page = $request->query->get('page', 1);
    $paginator = new Paginator();
    $paginator->subscribe(new \bicpi\MongoDbSubscriber());
    $pagination = $paginator->paginate($bounces, $page, $app['parameters']['limit_per_page']);

    return $app['twig']->render('bounces.html.twig', array(
        'page' => $page,
        'filter' => $filter,
        'pagination' => $pagination
    ));
})->bind('bounces');

$app->get('/complaints', function (Request $request) use ($app) {
    $complaints = $app['mongodb']->$app['parameters']['dbname']->complaints->find();

    return $app['twig']->render('complaints.html.twig', array(
        'complaints' => $complaints
    ));
})->bind('complaints');

$app->get('/subscription-confirmations', function (Request $request) use ($app) {
    $subscriptionConfirmations = $app['mongodb']->$app['parameters']['dbname']->subscriptionConfirmations->find();

    return $app['twig']->render('subscription-confirmations.html.twig', array(
        'subscriptionConfirmations' => $subscriptionConfirmations
    ));
})->bind('subscriptionConfirmations');

$app->post('/', function (Request $request) use ($app) {
    $notification = json_decode($request->getContent(), true);
    $accessor = PropertyAccess::createPropertyAccessor();
    $type = $accessor->getValue($notification, '[Type]');

        file_put_contents('notification.txt', json_encode($notification));
    if ('SubscriptionConfirmation' === $type) {
        $app['mongodb']->$app['parameters']['dbname']->notifications->insert(array(
            'type' => $type,
            'raw' => $request->getContent(),
            'MessageId' => $accessor->getValue($notification, '[MessageId]'),
            'Token' => $accessor->getValue($notification, '[Token]'),
            'TopicArn' => $accessor->getValue($notification, '[TopicArn]'),
            'Message' => $accessor->getValue($notification, '[Message]'),
            'SubscribeURL' => $accessor->getValue($notification, '[SubscribeURL]'),
            'Timestamp' => $accessor->getValue($notification, '[Timestamp]'),
            'SignatureVersion' => $accessor->getValue($notification, '[SignatureVersion]'),
            'Signature' => $accessor->getValue($notification, '[Signature]'),
            'SigningCertURL' => $accessor->getValue($notification, '[SigningCertURL]'),
        ));
    } else if ('Notification' === $type) {
        $message = json_decode($accessor->getValue($notification, '[Message]'), true);
        if ('Bounce' === $accessor->getValue($message, '[notificationType]')) {
            foreach ($accessor->getValue($message, '[bounce][bouncedRecipients]') as $bouncedRecipient) {
                $app['mongodb']->$app['parameters']['dbname']->bounces->insert(array(
                    'type' => $accessor->getValue($message, '[notificationType]'),
                    'raw' => $request->getContent(),
                    'bounce_type' => $accessor->getValue($message, '[bounce][bounceType]'),
                    'bounce_subType' => $accessor->getValue($message, '[bounce][bounceSubType]'),
                    'bounce_reportingMTA' => $accessor->getValue($message, '[bounce][reportingMTA]'),
                    'bounce_recipient' => $accessor->getValue($bouncedRecipient, '[emailAddress]'),
                    'bounce_status' => $accessor->getValue($bouncedRecipient, '[status]'),
                    'bounce_action' => $accessor->getValue($bouncedRecipient, '[action]'),
                    'bounce_diagnosticCode' => $accessor->getValue($bouncedRecipient, '[diagnosticCode]'),
                    'bounce_timestamp' => $accessor->getValue($message, '[bounce][timestamp]'),
                    'bounce_feedbackId' => $accessor->getValue($message, '[bounce][feedbackId]'),
                    'mail_timestamp' => $accessor->getValue($message, '[mail][timestamp]'),
                    'mail_messageId' => $accessor->getValue($message, '[mail][messageId]'),
                    'mail_source' => $accessor->getValue($message, '[mail][source]'),
                    'mail_destination' => implode(', ', $accessor->getValue($message, '[mail][destination]')),
                ));
            }
        }
        if ('Complaint' === $accessor->getValue($message, '[notificationType]')) {
            foreach ($accessor->getValue($message, '[complaint][complainedRecipients]') as $complainedRecipient) {
                $app['mongodb']->$app['parameters']['dbname']->complaints->insert(array(
                    'type' => $accessor->getValue($message, '[notificationType]'),
                    'raw' => $request->getContent(),
                    'complaint_recipient' => $accessor->getValue($complainedRecipient, '[emailAddress]'),
                    'complaint_userAgent' => $accessor->getValue($message, '[complaint][userAgent]'),
                    'complaint_complaintFeedbackType' => $accessor->getValue($message, '[complaint][complaintFeedbackType]'),
                    'complaint_arrivalDate' => $accessor->getValue($message, '[complaint][arrivalDate]'),
                    'complaint_timestamp' => $accessor->getValue($message, '[complaint][timestamp]'),
                    'complaint_feedbackId' => $accessor->getValue($message, '[complaint][feedbackId]'),
                    'mail_timestamp' => $accessor->getValue($message, '[mail][timestamp]'),
                    'mail_messageId' => $accessor->getValue($message, '[mail][messageId]'),
                    'mail_source' => $accessor->getValue($message, '[mail][source]'),
                    'mail_destination' => implode(', ', $accessor->getValue($message, '[mail][destination]')),
                ));
            }
        }
    }

    return new Response('', 503);
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
->method('GET|POST')
->bind('postRaw');

$app->run();
