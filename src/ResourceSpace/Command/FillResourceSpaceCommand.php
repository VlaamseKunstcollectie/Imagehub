<?php
namespace App\ResourceSpace\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FillResourceSpaceCommand extends ContainerAwareCommand
{
    private $apiUrl;
    private $apiUsername;
    private $apiKey;

    protected function configure()
    {
        $this
            ->setName('app:fill-resourcespace')
            ->addArgument('folder', InputArgument::OPTIONAL, 'The relative path of the folder containing the images')
            ->setDescription('Reads all images from the \'images\' folder and uploads them into the local ResourceSpace installation.')
            ->setHelp('This command ads all images from the \'images\' folder and uploads them into the local ResourceSpace installation. Optional parameter: the folder where the images are located, relative to this project.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $folder = $input->getArgument('folder');
        if (!$folder) {
            $folder = $this->getContainer()->getParameter('images_folder');
        }

        // Make sure the folder name ends with a trailing slash
        $folder = rtrim($folder, '/') . '/';

        $supportedExtensions = $this->getContainer()->getParameter('supported_extensions');

        // Make sure the API URL does not end with a ?
        $this->apiUrl = rtrim($this->getContainer()->getParameter('api_url'), '?');
        $this->apiUsername = $this->getContainer()->getParameter('api_username');
        $this->apiKey = $this->getContainer()->getParameter('api_key');


        $resourceSpaceData = $this->getCurrentResourceSpaceData();


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
                $this->processImage($resourceSpaceData, $folder. $imageFile);
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

    protected function processImage($resourceSpaceData, $image)
    {
        $md5 = md5_file($image);
        $exifData = exif_read_data($image);
        var_dump($exifData);
        foreach($exifData as $key => $value) {
            echo $key. PHP_EOL;
        }
        $workPid = $exifData['DocumentName'];
        $dataPid = $exifData['ImageDescription'];
        echo PHP_EOL;
        echo $workPid . PHP_EOL . $dataPid . PHP_EOL;
    }

    protected function importIntoResourceSpace()
    {
    }

    protected function getSign($query)
    {
        return hash('sha256', $this->apiKey . $query);
    }
}
