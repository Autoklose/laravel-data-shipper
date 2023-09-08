<?php

namespace Autoklose\DataShipper\Subscribers\Contracts;

interface DataSubscriberInterface
{
    public function ship($packages);
}
