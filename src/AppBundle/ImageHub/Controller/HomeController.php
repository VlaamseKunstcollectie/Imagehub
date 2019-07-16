<?php

namespace AppBundle\ImageHub\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends Controller
{
    /**
     * @Route("/", name="home")
     */
    public function homeAction(Request $request)
    {
        $dm = $this->get('doctrine_mongodb')->getManager();
        $count = $dm->createQueryBuilder('AppBundle\ImageHub\ManifestBundle\Document\Manifest')->count()->getQuery()->execute();

        return $this->render('home.html.twig', [
            'current_page' => 'home',
            'documentCount' => $count
        ]);
    }
}
