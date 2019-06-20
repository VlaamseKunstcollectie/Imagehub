<?php

namespace AppBundle\ImageHub\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CanvasController extends Controller
{
    /**
     * @Route("/iiif/2/{manifestId}/canvas/{canvasIndex}", name="canvas")
     */
    public function getCanvas(Request $request, $manifestId = '', $canvasIndex = '')
    {
        $dm = $this->get('doctrine_mongodb')->getManager();
        $canvas = $dm->createQueryBuilder('AppBundle\ImageHub\CanvasBundle\Document\Canvas')->field('canvasId')->equals('https://imagehub.vlaamsekunstcollectie.be/iiif/2/' . $manifestId . '/canvas/' . $canvasIndex);
        echo $canvas;
    }
}
