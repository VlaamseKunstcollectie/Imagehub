<?php

namespace AppBundle\ImageHub\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class IndexController extends Controller
{
    /**
     * @Route("/", name="index")
     */
    public function indexAction(Request $request)
    {
        $dm = $this->get('doctrine_mongodb')->getManager();
        $count = $dm->createQueryBuilder('AppBundle\ImageHub\ManifestBundle\Document\Manifest')->count()->getQuery()->execute();

        // replace this example code with whatever you need
        return $this->render('index.html.twig', [
            'documentCount' => $count
        ]);
    }
}
