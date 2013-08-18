<?php

namespace bicpi;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicControllerProvider implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $controllers = $app['controllers_factory'];

        $controllers->get('/', $this->home())->bind('home');
        $controllers->post('/', $this->postNotification())->bind('postNotification');
        $controllers->get('/login', $this->login());

        return $controllers;
    }

    private function home()
    {
        return function (Application $app) {
            return $app->redirect($app['url_generator']->generate('notifications'));
        };
    }

    private function postNotification()
    {
        return function (Request $request, Application $app) {
            $accessor = $app['accessor'];
            $notification = json_decode($request->getContent(), true);

            if (null === $notification) {
                $app->abort(501, 'Invalid JSON');
            }

            $type = $accessor->getValue($notification, '[Type]');
            if ('SubscriptionConfirmation' == $type) {
                $logfile = __DIR__.'/../logs/app.log';
                $content = '';
                if (file_exists($logfile)) {
                    $content = file_get_contents($logfile);
                }
                file_put_contents($logfile, $content.$request->getContent() . "\n");

                return new Response('Subscription confirmation created', 201);
            }

            if ('Notification' != $type) {
                $app->abort(501, 'Invalid JSON');
            }

            $message = json_decode($app['accessor']->getValue($notification, '[Message]'), true);
            if (null === $message) {
                $app->abort(501, 'Invalid JSON');
            }

            $notificationType = $accessor->getValue($message, '[notificationType]');
            if ('Bounce' == $notificationType) {
                if ($bouncedRecipients = $accessor->getValue($message, '[bounce][bouncedRecipients]')) {
                    foreach ($bouncedRecipients as $bouncedRecipient) {
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

                    return new Response('Bounce created', 201);
                }
            } else if ('Complaint' == $notificationType) {
                if ($complainedRecipients = $accessor->getValue($message, '[complaint][complainedRecipients]')) {
                    foreach ($complainedRecipients as $complainedRecipient) {
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

                    return new Response('Complaint created', 201);
                }
            }

            $app->abort(501, 'Invalid notification');
        };
    }

    private function login()
    {
        return function(Request $request, Application $app) {
            return $app['twig']->render('login.html.twig', array(
                'error'         => $app['security.last_error']($request),
                'last_username' => $app['session']->get('_security.last_username'),
            ));
        };
    }
}