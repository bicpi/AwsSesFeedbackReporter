<?php

namespace bicpi;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;

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
        $controller = $this;
        return function (Request $request, Application $app) use ($controller) {
            $accessor = $app['accessor'];
            $notification = json_decode($request->getContent(), true);

            if (null === $notification) {
                $app->abort(501, 'Invalid JSON');
            }
            $type = $accessor->getValue($notification, '[Type]');
            if ('SubscriptionConfirmation' == $type) {
                applog($request->getContent());

                return new Response('Subscription confirmation created', 201);
            }

            if ('Notification' != $type) {
                $app->abort(501, 'Invalid JSON');
            }

            $message = json_decode($app['accessor']->getValue($notification, '[Message]'), true);
            if (null === $message) {
                $app->abort(501, 'Invalid JSON');
            }

            if ('Bounce' == $accessor->getValue($message, '[notificationType]')) {
                $errors = $app['validator']->validateValue($message, $controller->getBounceConstraints());
                if (count($errors)) {
                    $messages = array(
                        sprintf('%s Error when receiving bounce:', date('Y-m-d H:i:s'))
                    );
                    foreach ($errors as $errNo => $error) {
                        $messages[] = sprintf('%s%d: %s %s', str_repeat(' ', 4), $errNo+1, $error->getPropertyPath(), $error->getMessage());
                    }
                    applog(implode("\n", $messages));
                    $app->abort(501, 'Invalid bounce');
                }

                foreach ($message['bounce']['bouncedRecipients'] as $bouncedRecipient) {
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

                return new Response('Bounce(s) created', 201);
            }

            if ('Complaint' == $accessor->getValue($message, '[notificationType]')) {
                $errors = $app['validator']->validateValue($message, $controller->getComplaintConstraints());
                if (count($errors)) {

                }

                foreach ($message['complaint']['complainedRecipients'] as $complainedRecipient) {
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

                return new Response('Complaint(s) created', 201);
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

    public function getBounceConstraints()
    {
        return new Assert\Collection(array(
            'notificationType' => new Assert\EqualTo(array('value' => 'Bounce')),
            'bounce' => new Assert\Collection(array(
                'bounceType' => new Assert\NotBlank(),
                'bounceSubType' => new Assert\NotBlank(),
                'reportingMTA' => new Assert\Optional(),
                'timestamp' => new Assert\NotBlank(),
                'feedbackId' => new Assert\NotBlank(),
                'bouncedRecipients' => new Assert\All(array(
                    new Assert\Collection(array(
                        'emailAddress' => array(
                            new Assert\NotBlank(),
                            new Assert\Email(),
                        ),
                        'status' => new Assert\Optional(),
                        'diagnosticCode' => new Assert\Optional(),
                        'action' =>new Assert\Optional(),
                    ))
                ))
            )),
            'mail' => new Assert\Collection(array(
                'destination' => new Assert\All(array(
                    new Assert\NotBlank(),
                    new Assert\Email(),
                )),
                'messageId' => new Assert\NotBlank(),
                'timestamp' => new Assert\NotBlank(),
                'source' => array(
                    new Assert\NotBlank(),
                    new Assert\Email(),
                ),
            )),
        ));
    }

    public function getComplaintConstraints()
    {
        return new Assert\Collection(array(
            'notificationType' => new Assert\EqualTo(array('value' => 'Complaint')),
            'complaint' => new Assert\Collection(array(
                'complainedRecipients' => new Assert\All(array(
                    new Assert\Collection(array(
                        'emailAddress' => array(
                            new Assert\NotBlank(),
                            new Assert\Email(),
                        ),
                    ))
                )),
                'timestamp' => new Assert\NotBlank(),
                'feedbackId' => new Assert\NotBlank(),
                'userAgent' => new Assert\Optional(),
                'complaintFeedbackType' => new Assert\Optional(),
                'arrivalDate' => new Assert\Optional(),
            )),
            'mail' => new Assert\Collection(array(
                'destination' => new Assert\All(array(
                    new Assert\NotBlank(),
                    new Assert\Email(),
                )),
                'messageId' => new Assert\NotBlank(),
                'timestamp' => new Assert\NotBlank(),
                'source' => array(
                    new Assert\NotBlank(),
                    new Assert\Email(),
                ),
            )),
        ));
    }
}