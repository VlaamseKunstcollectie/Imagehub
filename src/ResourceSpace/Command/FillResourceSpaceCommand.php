<?php
namespace App\ResourceSpace\Command;

use DOMDocument;
use DOMXPath;
use Phpoaipmh\Endpoint;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FillResourceSpaceCommand extends ContainerAwareCommand
{
    private $apiUrl;
    private $apiUsername;
    private $apiKey;
    private $resourceSpaceData;
    private $datahubUrl;
    private $namespace;
    private $metadataPrefix;
    private $dataDefinition;
    private $datahubEndpoint;

    protected function configure()
    {
        $this
            ->setName('app:fill-resourcespace')
            ->addArgument('folder', InputArgument::OPTIONAL, 'The relative path of the folder containing the images')
            ->addArgument('url', InputArgument::OPTIONAL, 'The URL of the Datahub')
            ->setDescription('Reads all images from the \'images\' folder and uploads them into the local ResourceSpace installation.')
            ->setHelp('This command reads all images from the \'images\' folder, compares the data to whatever is currently in ResourceSpace and uploads them into the local ResourceSpace installation. Optional parameters: the folder where the images are located relative to this project and the Datahub URL.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $folder = $input->getArgument('folder');
        if (!$folder) {
            $folder = $this->getContainer()->getParameter('images_folder');
        }
        $this->datahubUrl = $input->getArgument('url');
        if(!$this->datahubUrl) {
            $this->datahubUrl = $this->getContainer()->getParameter('datahub.url');
        }
        $this->namespace = $this->getContainer()->getParameter('datahub.namespace');
        $this->metadataPrefix = $this->getContainer()->getParameter('datahub.metadataprefix');
        $this->dataDefinition = $this->getContainer()->getParameter('datahub.data_definition');

        // Make sure the folder name ends with a trailing slash
        $folder = rtrim($folder, '/') . '/';

        $supportedExtensions = $this->getContainer()->getParameter('supported_extensions');

        // Make sure the API URL does not end with a ?
        $this->apiUrl = rtrim($this->getContainer()->getParameter('api_url'), '?');
        $this->apiUsername = $this->getContainer()->getParameter('api_username');
        $this->apiKey = $this->getContainer()->getParameter('api_key');


        $this->resourceSpaceData = $this->getCurrentResourceSpaceData();


        // Loop through all files in the folder
        $imageFiles = scandir($folder);
        foreach ($imageFiles as $imageFile) {
            $info = pathinfo($imageFile);
            $isSupportedImage = false;

            // Check if the file is in (one of) the supported format(s)
            foreach ($supportedExtensions as $supportedExtension) {
                if ($info['extension'] == $supportedExtension) {
                    $isSupportedImage = true;
                    break;
                }
            }
            if ($isSupportedImage) {
                $this->processImage($folder. $imageFile);
            } else {
                // Log incorrect file extension
            }
        }
    }

    protected function getCurrentResourceSpaceData()
    {
        $query = 'user=' . $this->apiUsername . '&function=do_search&param1=';
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $data = file_get_contents($url);
        return $data;
    }

    protected function processImage($image)
    {
        $md5 = md5_file($image);
        $exifData = exif_read_data($image);
        $workPid = $exifData['DocumentName'];
        $dataPid = $exifData['ImageDescription'];
        $fileName = $exifData['FileName'];
        $mimeType = $exifData['MimeType'];
        $photographer = $exifData['Artist'];
        $copyright = $exifData['Copyright'];
        $imageWidth = $exifData['ImageWidth'];
        $imageHeight = $exifData['ImageLength'];
        $dateTime = $exifData['DateTime'];
//        $description = $exifData['Description'];
//        $megaPixels = $exifData['MegaPixels'];

        $datahubData = array();


        if(!$this->datahubEndpoint)
            $this->datahubEndpoint = Endpoint::build($this->datahubUrl);

        $record = $this->datahubEndpoint->getRecord($dataPid, $this->metadataPrefix);
        $data = $record->GetRecord->record->metadata->children($this->namespace, true);
        $domDoc = new DOMDocument;
        $domDoc->loadXML($data->asXML());
        $xpath = new DOMXPath($domDoc);

        foreach($this->dataDefinition as $key => $dataDef) {
            $query = $this->buildXpath($dataDef['xpath'], 'nl');
            $extracted = $xpath->query($query);
            $value = null;
            if($extracted) {
                if(count($extracted) > 0) {
                    foreach($extracted as $extr) {
                        if($extr->nodeValue !== 'n/a') {
                            $value = $extr->nodeValue;
                        }
                    }
                }
            }
            if($value != null) {
                $datahubData[$key] = $value;
            }
        }
        var_dump($datahubData);
    }

    protected function importIntoResourceSpace()
    {
    }

    protected function getSign($query)
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
