<?php

namespace bicpi;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Knp\Component\Pager\Paginator;
use bicpi\MongoDbSubscriber;

class AdminControllerProvider implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $controllers = $app['controllers_factory'];

        $controllers->get('/notifications', $this->notifications())->bind('notifications');
        $controllers->get('/notifications/export/{exportType}', $this->export())->bind('notificationsExport');
        $controllers->get('/notification/{_id}/remove', $this->removeNotification())->bind('deleteNotification');
        $controllers->match('/post-raw', $this->postRaw())->method('GET|POST')->bind('postRaw');

        return $controllers;
    }

    private function notifications()
    {
        return function (Request $request, Application $app) {
            $filter = array_filter($request->query->get('filter', array()));

            $notifications = $app['mongodb']->notifications
                ->find(array_map(function($val){ return array('$regex' => preg_quote($val));}, $filter))
                ->sort(array('timestamp' => -1));

            $page = $request->query->get('page', 1);
            $paginator = new Paginator();
            $paginator->subscribe(new MongoDbSubscriber());
            $pagination = $paginator->paginate($notifications, $page, $app['parameters']['limit_per_page']);

            return $app['twig']->render('notifications.html.twig', array(
                'page' => $page,
                'filter' => $filter,
                'pagination' => $pagination
            ));
        };
    }

    private function export()
    {
        return function (Request $request, Application $app, $exportType) {
            $filter = array_filter($request->query->get('filter', array()));

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
                case 'recipients-only':
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
        };
    }

    private function removeNotification()
    {
        return function (Application $app, $_id) {
            $app['mongodb']->notifications->remove(array('_id' => new \MongoId($_id)));

            return $app->redirect($app['url_generator']->generate('notifications'));
        };
    }

    private function postRaw()
    {
        return function (Request $request, Application $app) {
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
        };
    }
}