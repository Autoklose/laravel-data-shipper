<?php

namespace Autoklose\DataShipper;

use Autoklose\DataShipper\Contracts\PackageInterface;
use Illuminate\Support\Str;

class Package implements PackageInterface {
    public const MODE_UPDATE = 'update';
    public const MODE_CREATE = 'create';
    private $id;
    private $uuid;
    private $payload;
    private $mode;
    private $className;

    public function __construct($id, $payload, $className, $mode = self::MODE_UPDATE, $uuid = null) {
        $this->id = $id;
        $this->uuid = $uuid ?? Str::uuid()->toString();
        if (Str::isJson($payload)) {
            $payload = $this->unpack($payload);
        }
        $this->mode = $mode;
        $this->className = $className;
        $this->payload = $payload;
    }

    /**
     * Retrieve the id of the model associated with the package
     *
     * @return mixed
     */
    public function id() {
        return $this->id;
    }

    /**
     * Return auto generated uuid that can be used for retrieval & storage with redis
     *
     * @return mixed|string
     */
    public function uuid() {
        return $this->uuid;
    }

    /**
     * Return the mode of the package (update/create)
     *
     * @return string
     */
    public function mode()
    {
        return $this->mode;
    }

    /**
     * Return the class name of the model associated with the package
     *
     * @return string
     */
    public function className()
    {
        return $this->className;
    }

    /**
     * JSON encode the payload for storage in redis
     *
     * @return false|string
     */
    public function pack() {
        return json_encode($this->payload);
    }

    /**
     * JSON decode the stored package
     *
     * @param $payload
     * @return mixed
     */
    public function unpack($payload) {
        return json_decode($payload, true);
    }

    /**
     * Get the payload of all changes for the package
     *
     * @return mixed
     */
    public function getPayload() {
        return $this->payload;
    }
}
