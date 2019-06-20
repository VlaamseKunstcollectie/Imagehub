<?php

namespace AppBundle\ImageHub\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ManifestController extends Controller
{
    /**
     * @Route("/iiif/2/{manifestId}/manifest", name="manifest")
     */
    public function getManifest(Request $request, $manifestId = '')
    {
        $dm = $this->get('doctrine_mongodb')->getManager();
        $manifest = $dm->createQueryBuilder('AppBundle\ImageHub\ManifestBundle\Document\Manifest')->field('manifestId')->equals('https://imagehub.vlaamsekunstcollectie.be/iiif/2/' . $manifestId . '/manifest');
        echo $manifest;
    }
}
