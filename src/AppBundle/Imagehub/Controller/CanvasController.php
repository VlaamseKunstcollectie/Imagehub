<?php

namespace AppBundle\Imagehub\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CanvasController extends Controller
{
    /**
     * @Route("/iiif/2/{manifestId}/canvas/{canvasIndex}.json", name="canvas")
     */
    public function getCanvas(Request $request, $manifestId = '', $canvasIndex = '')
    {
        // Make sure the service URL name ends with a trailing slash
        $baseUrl = rtrim($this->getParameter('service_url'), '/') . '/';

        $dm = $this->get('doctrine_mongodb')->getManager();
        $canvases = $dm->createQueryBuilder('AppBundle\Imagehub\CanvasBundle\Document\Canvas')->field('canvasId')->equals($baseUrl . $manifestId . '/canvas/' . $canvasIndex . '.json')->getQuery()->execute();
        if(count($canvases) > 0) {
            foreach($canvases as $canvas) {
                $toServe = $canvas->getData();
            }
            $headers = array(
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*'
            );
            return new Response(json_encode(json_decode($toServe), JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE), 200, $headers);
        } else {
            return new Response('Sorry, the requested document does not exist.', 404);
        }
    }
}
