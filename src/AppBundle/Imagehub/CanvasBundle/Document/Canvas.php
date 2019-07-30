<?php

namespace AppBundle\Imagehub\CanvasBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/**
 * @ODM\Document(collection="canvas")
 */
class Canvas
{
    /**
     * @ODM\Id
     */
    private $id;

    /**
     * @ODM\Field(type="string")
     */
    private $canvasId;

    /**
     * @ODM\Field(type="string")
     */
    private $data;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getCanvasId()
    {
        return $this->canvasId;
    }

    public function setCanvasId($canvasId)
    {
        $this->canvasId = $canvasId;
    }

    public function getData()
    {
        return $this->data;
    }

    public function setData($data)
    {
        $this->data = $data;
    }
}
