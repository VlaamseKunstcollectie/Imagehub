<?php

namespace AppBundle\ImageHub\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ManualController extends Controller
{
    /**
     * @Route("/manual", name="manual")
     */
    public function manualAction(Request $request)
    {
        return $this->render('manual.html.twig', [
            'current_page' => 'manual'
        ]);
    }
}
