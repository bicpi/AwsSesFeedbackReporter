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
$app->register(new Silex\Provider\SecurityServiceProvider(), array(
    'security.firewalls' => array(
        'admin' => array(
            'pattern' => '^/',
            'http' => true,
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
        array('^/notifications', 'ROLE_ONEMEDIA'),
        array('^/admin', 'ROLE_ADMIN'),
    )
));
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

$app->get('/', function () use ($app) {
    return $app->redirect($app['url_generator']->generate('notifications'));
})->bind('home');

$app->get('/notifications', function (Request $request) use ($app) {
    $filter = array_filter($request->query->get('filter', array()));

    if ($export = $request->query->get('_export')) {
        return export($export, $filter);
    }

    $notifications = $app['mongodb']->notifications
        ->find(array_map(function($val){ return array('$regex' => preg_quote($val));}, $filter))
        ->sort(array('timestamp' => -1));

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

$app->get('/admin/notification/{_id}/remove', function (Request $request, $_id) use ($app) {
    $app['mongodb']->notifications->remove(array('_id' => new \MongoId($_id)));

    return $app->redirect($app['url_generator']->generate('notifications'));
})->bind('deleteNotification');

$app->match('/admin/post-raw', function (Request $request) use ($app) {
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

        return $app->redirect($app['url_generator']->generate('notifications'));
    }

    return $app['twig']->render('post-raw.html.twig');
})
->method('GET|POST')
->bind('postRaw');

$app->post('/', function (Request $request) use ($app) {
    $notification = json_decode($request->getContent(), true);

    if ('SubscriptionConfirmation' == $app['accessor']->getValue($notification, '[Type]')) {
        $logfile = __DIR__.'/../logs/app.log';
        $content = '';
        if (file_exists($logfile)) {
            $content = file_get_contents($logfile);
        }
        file_put_contents($logfile, $content.$request->getContent() . "\n");

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
})->bind('postNotification');

$app->run();


function export($exportType, array $filter)
{
    global $app;

    $headingStyles = array(
        'font' => array('bold' => true),
        'fill' => array(
            'type' => \PHPExcel_Style_Fill::FILL_SOLID,
            'color' => array('argb' => 'F2F2F2'),
        ),
        'borders' => array(
            'allborders' => array(
                'style' => \PHPExcel_Style_Border::BORDER_THIN,
                'color' => array('argb' => 'FFCCCCCC')
            )
        )
    );

    $bounces = $app['mongodb']->notifications
        ->find(array_merge(
            array_map(function($val){ return array('$regex' => preg_quote($val));}, $filter),
            array('notificationType' => 'Bounce')
        ))
        ->sort(array('timestamp' => -1));

    $complaints = $app['mongodb']->notifications
        ->find(array_merge(
            array_map(function($val){ return array('$regex' => preg_quote($val));}, $filter),
            array('notificationType' => 'Complaint')
        ))
        ->sort(array('timestamp' => -1));

    $excel = new \PHPExcel();
    $excel
        ->getActiveSheet()
        ->setTitle(sprintf('Bounces (%d)', $bounces->count()));

    $excel
        ->createSheet()
        ->setTitle(sprintf('Complaints (%d)', $complaints->count()));

    switch ($exportType) {
        case 'recipients_only':
            $sheet = $excel->getSheet(0);
            $row = 1;
            foreach ($bounces as $bounce) {
                $col = 0;
                $sheet->setCellValueByColumnAndRow($col++, $row, $bounce['recipient']);
                $row++;
            }
            $sheet = $excel->getSheet(1);
            $row = 1;
            foreach ($complaints as $complaint) {
                $col = 0;
                $sheet->setCellValueByColumnAndRow($col++, $row, $complaint['recipient']);
                $row++;
            }
            break;
        case 'detailed':
            $sheet = $excel->getSheet(0);
            $headers = array(
                'Bounced recipient',
                'Bounced at',
                'Sendout return path',
                'Origin sendout at',
                'Bounce type',
                'Bounce subtype',
                'Bounce message',
            );
            $row = 1;
            $col = 0;
            foreach ($headers as $header) {
                $sheet->setCellValueByColumnAndRow($col++, $row, $header);
            }
            $row = 2;
            foreach ($bounces as $bounce) {
                $col = 0;
                $sheet->setCellValueByColumnAndRow($col++, $row, $bounce['recipient']);
                $sheet->setCellValueByColumnAndRow($col++, $row, $bounce['timestamp']);
                $sheet->setCellValueByColumnAndRow($col++, $row, $bounce['mail_source']);
                $sheet->setCellValueByColumnAndRow($col++, $row, $bounce['mail_timestamp']);
                $sheet->setCellValueByColumnAndRow($col++, $row, $bounce['type']);
                $sheet->setCellValueByColumnAndRow($col++, $row, $bounce['subType']);
                $sheet->setCellValueByColumnAndRow($col++, $row, $bounce['diagnosticCode']);
                $row++;
            }
            $sheet->getStyle(sprintf('A1:%s1', $sheet->getHighestColumn()))->applyFromArray($headingStyles);

            $sheet = $excel->getSheet(1);
            $headers = array(
                'Complained recipient',
                'Sendout return path',
                'Complained at',
                'Complained message',
                'Origin sendout at',
            );
            $row = 1;
            $col = 0;
            foreach ($headers as $header) {
                $sheet->setCellValueByColumnAndRow($col++, $row, $header);
            }
            $row = 2;
            foreach ($complaints as $complaint) {
                $col = 0;
                $sheet->setCellValueByColumnAndRow($col++, $row, $complaint['recipient']);
                $sheet->setCellValueByColumnAndRow($col++, $row, $complaint['mail_source']);
                $sheet->setCellValueByColumnAndRow($col++, $row, $complaint['timestamp']);
                $sheet->setCellValueByColumnAndRow($col++, $row, $complaint['complaintFeedbackType']);
                $sheet->setCellValueByColumnAndRow($col++, $row, $complaint['mail_timestamp']);
                $row++;
            }
            $sheet->getStyle(sprintf('A1:%s1', $sheet->getHighestColumn()))->applyFromArray($headingStyles);
            break;
        default:
            throw new \Exception('Export type not implemented.');
    }

    ob_start();
    \PHPExcel_IOFactory::createWriter($excel, 'Excel2007')->save('php://output');
    $content = ob_get_clean();

    $filename = sprintf('%s_%s_notifications.xlsx', date('Y-m-d_H-i-s'), $exportType);

    return new Response($content, 200, array(
        'Content-Type' => 'text/csv;charset=utf-8',
        'Content-Length' => strlen($content),
        'Content-Disposition' => sprintf('attachment; filename="%s"', urlencode($filename)),
    ));
    \PHPExcel_IOFactory::createWriter($excel, 'Excel2007')->save('php://output');
    exit;

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
        $app['mongodb']->notifications->insert(array(
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
        $app['mongodb']->notifications->insert(array(
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
