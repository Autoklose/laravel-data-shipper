<?php

namespace Autoklose\DataShipper\Contracts;

interface PackageInterface
{
    public function id();
    public function uuid();
    public function pack();
    public function unpack($payload);
    public function getPayload();
}
