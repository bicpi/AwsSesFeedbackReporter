<?php

namespace bicpi;

class Paginator
{
    private $count;
    private $items;
    private $page;
    private $maxPerPage;

    public function __construct(\MongoCursor $cursor, $page = 1, $maxPerPage = 10)
    {
        $cursor = $cursor
            ->skip(($page-1)*$maxPerPage)
            ->limit($maxPerPage);
        $this->count = $cursor->count();
        $this->items = $cursor;
        $this->page = $page;
        $this->maxPerPage = $maxPerPage;
    }

    public function getCount()
    {
        return $this->count;
    }

    public function getItems()
    {
        return $this->items;
    }

    public function getPage()
    {
        return $this->page;
    }

    public function getMaxPerPage()
    {
        return $this->maxPerPage;
    }

    public function getLastPage()
    {
        return ceil($this->count/$this->maxPerPage);
    }
}