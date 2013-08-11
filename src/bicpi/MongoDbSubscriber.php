<?php

namespace bicpi;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Knp\Component\Pager\Event\ItemsEvent;

class MongoDbSubscriber implements EventSubscriberInterface
{
    public function items(ItemsEvent $event)
    {
        if ($event->target instanceof \MongoCursor) {
            $event->count = $event->target->count();
            $event->items = iterator_to_array($event->target->skip($event->getOffset())->limit($event->getLimit()));
            $event->stopPropagation();
        }
    }

    public static function getSubscribedEvents()
    {
        return array(
            'knp_pager.items' => array('items', 0)
        );
    }
}
