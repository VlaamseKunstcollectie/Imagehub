<?php

namespace AppBundle\ResourceSpace\ImageBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/**
 * @ODM\Document(collection="image")
 */
class Image
{
    /**
     * @ODM\Id
     */
    private $id;

    /**
     * @ODM\Field(type="string")
     */
    private $filename;

    /**
     * @ODM\Field(type="string")
     */
    private $hash;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getFilename()
    {
        return $this->filename;
    }

    public function setFilename($filename)
    {
        $this->filename = $filename;
    }

    public function getHash()
    {
        return $this->hash;
    }

    public function setHash($hash)
    {
        $this->hash = $hash;
    }

}
