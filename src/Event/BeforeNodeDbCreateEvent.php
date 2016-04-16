<?php

namespace Casebox\CoreBundle\Event;

use Casebox\CoreBundle\Service\Objects\Object as ObjectsObject;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class BeforeNodeDbCreateEvent
 */
class BeforeNodeDbCreateEvent extends Event
{
    /**
     * @var Object
     */
    protected $params;

    /**
     * BeforeNodeDbCreateEvent constructor
     * @param ObjectsObject $object
     */
    public function __construct(ObjectsObject $object)
    {
        $this->params = $object;
    }

    /**
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }
}
