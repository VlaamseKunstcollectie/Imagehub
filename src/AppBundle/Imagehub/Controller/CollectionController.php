<?php

namespace AppBundle\Imagehub\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CollectionController extends Controller
{
    /**
     * @Route("/iiif/2/collection/top", name="collection")
     */
    public function collectionAction(Request $request)
    {
        // Make sure the service URL name ends with a trailing slash
        $baseUrl = rtrim($this->getParameter('service_url'), '/') . '/';

        $dm = $this->get('doctrine_mongodb')->getManager();
        $manifests = $dm->createQueryBuilder('AppBundle\Imagehub\ManifestBundle\Document\Manifest')->field('manifestId')->equals($baseUrl . 'collection/top')->getQuery()->execute();
        $toServe = 'Sorry, the requested document does not exist.';
        if(count($manifests) > 0) {
            foreach($manifests as $manifest) {
                $toServe = $manifest->getData();
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
