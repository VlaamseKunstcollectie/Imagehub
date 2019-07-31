<?php

namespace AppBundle\Imagehub\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class IIIFController extends Controller
{
    /**
     * @Route("/imagehub", name="imagehub")
     */
    public function iiifAction(Request $request)
    {
        return $this->render('imagehub.html.twig', [
            'current_page' => 'imagehub'
        ]);
    }
}
