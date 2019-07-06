<?php

namespace AppBundle\ImageHub\ManifestBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/**
 * @ODM\Document(collection="manifest")
 */
class Manifest
{
    /**
     * @ODM\Id
     */
    private $id;

    /**
     * @ODM\Field(type="string")
     */
    private $manifestId;

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

    public function getManifestId()
    {
        return $this->manifestId;
    }

    public function setManifestId($manifestId)
    {
        $this->manifestId = $manifestId;
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
