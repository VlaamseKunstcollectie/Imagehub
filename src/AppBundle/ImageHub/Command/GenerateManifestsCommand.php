<?php
namespace AppBundle\ImageHub\Command;

use AppBundle\ImageHub\ManifestBundle\Document\Manifest;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateManifestsCommand extends ContainerAwareCommand
{
    private $apiUrl;
    private $apiUsername;
    private $apiKey;
    private $datahubUrl;
    private $namespace;
    private $metadataPrefix;
    private $dataDefinition;
    private $datahubEndpoint;

    protected function configure()
    {
        $this
            ->setName('app:generate-manifests')
            ->addArgument("url", InputArgument::OPTIONAL, "The URL of the Datahub")
            ->setDescription('Fetches all data from ResourceSpace, Cantaloupe and the Datahub and stores the relevant information in a local database.')
            ->setHelp('This command fetches all data from ResourceSpace, Cantaloupe and the Datahub and stores the relevant information in a local database.\nOptional parameter: the URL of the datahub. If the URL equals "skip", it will not fetch data and use whatever is currently in the database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->datahubUrl = $input->getArgument('url');
        if(!$this->datahubUrl) {
            $this->datahubUrl = $this->getContainer()->getParameter('datahub.url');
        }
        $this->namespace = $this->getContainer()->getParameter('datahub.namespace');
        $this->metadataPrefix = $this->getContainer()->getParameter('datahub.metadataprefix');
        $this->dataDefinition = $this->getContainer()->getParameter('datahub.data_definition');
        $this->exifFields = $this->getContainer()->getParameter('exif_fields');

        // Make sure the API URL does not end with a '?' character
        $this->apiUrl = rtrim($this->getContainer()->getParameter('api_url'), '?');
        $this->apiUsername = $this->getContainer()->getParameter('api_username');
        $this->apiKey = $this->getContainer()->getParameter('api_key');

        $this->generateManifests();
    }

    private function generateManifests()
    {

        $dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $dm->getDocumentCollection('ManifestBundle:Manifest')->remove([]);

        $query = 'user=' . $this->apiUsername . '&function=do_search&param1=';
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $allResources = file_get_contents($url);
        $resources = json_decode($allResources, true);

        foreach($resources as $resource) {
            //TODO properly generate manifests
            $currentData = $this->getResourceInfo($resource['ref']);

            $manifestDocument = new Manifest();
            $manifestDocument->setData($currentData);
            $dm->persist($manifestDocument);
            $dm->flush();
            $dm->clear();
        }
    }

    private function getResourceInfo($id)
    {
        $query = 'user=' . $this->apiUsername . '&function=get_resource_field_data&param1=' . $id;
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $data = file_get_contents($url);
        return $data;
    }

    private function getSign($query)
    {
        return hash('sha256', $this->apiKey . $query);
    }

    // Build the xpath based on the provided namespace
    private function buildXpath($xpath, $language)
    {
        $xpath = str_replace('{language}', $language, $xpath);
        $xpath = str_replace('[@', '[@' . $this->namespace . ':', $xpath);
        $xpath = str_replace('[@' . $this->namespace . ':xml:', '[@xml:', $xpath);
        $xpath = preg_replace('/\[([^@])/', '[' . $this->namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\/([^\/])/', '/' . $this->namespace . ':${1}', $xpath);
        if(strpos($xpath, '/') !== 0) {
            $xpath = $this->namespace . ':' . $xpath;
        }
        $xpath = 'descendant::' . $xpath;
        return $xpath;
    }
}
