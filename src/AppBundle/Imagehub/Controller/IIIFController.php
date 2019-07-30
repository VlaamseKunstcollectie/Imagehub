<?php

namespace AppBundle\Imagehub\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class IIIFController extends Controller
{
    /**
     * @Route("/iiif", name="iiif")
     */
    public function iiifAction(Request $request)
    {
        return $this->render('iiif.html.twig', [
            'current_page' => 'iiif'
        ]);
    }
}
