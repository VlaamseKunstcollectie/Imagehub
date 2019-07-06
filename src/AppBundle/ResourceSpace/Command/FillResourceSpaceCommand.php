<?php
namespace AppBundle\ResourceSpace\Command;

use AppBundle\ResourceSpace\ImageBundle\Document\Image;
use DOMDocument;
use DOMXPath;
use Exception;
use Imagick;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
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
    private $datahubLanguage;
    private $namespace;
    private $metadataPrefix;
    private $dataDefinition;
    private $datahubEndpoint;
    private $exifFields;

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
            $this->datahubUrl = $this->getContainer()->getParameter('datahub_url');
        }
        $this->datahubLanguage = $this->getContainer()->getParameter('datahub_language');
        $this->namespace = $this->getContainer()->getParameter('datahub_namespace');
        $this->metadataPrefix = $this->getContainer()->getParameter('datahub_metadataprefix');
        $this->dataDefinition = $this->getContainer()->getParameter('datahub_data_definition');
        $this->exifFields = $this->getContainer()->getParameter('exif_fields');

        // Make sure the folder name ends with a trailing slash
        $folder = rtrim($folder, '/') . '/';

        $supportedExtensions = $this->getContainer()->getParameter('supported_extensions');

        // Make sure the API URL does not end with a '?' character
        $this->apiUrl = rtrim($this->getContainer()->getParameter('resourcespace_api_url'), '?');
        $this->apiUsername = $this->getContainer()->getParameter('resourcespace_api_username');
        $this->apiKey = $this->getContainer()->getParameter('resourcespace_api_key');


        $this->resourceSpaceData = $this->getCurrentResourceSpaceData();
        if($this->resourceSpaceData === NULL) {
            return;
        }

        $dm = $this->getContainer()->get('doctrine_mongodb')->getManager();

        // Loop through all files in the folder
        $imageFiles = scandir($folder);
        foreach ($imageFiles as $imageName) {
            if($imageName == '.' || $imageName == '..') {
                continue;
            }
            $fullImagePath = $folder . $imageName;
            if(is_dir($fullImagePath) || !is_file($fullImagePath)) {
                continue;
            }
            try {
                $isSupportedImage = false;

                if(strpos($imageName, '.') > -1) {
                    //TODO log incorrect filename
                    continue;
                }

                //TODO check if this image uses JPEG compression


                $exifData = exif_read_data($fullImagePath);

                // Check if the file is in (one of) the supported format(s)
                if($exifData) {
                    if(array_key_exists('MimeType', $exifData)) {
                        foreach ($supportedExtensions as $supportedExtension) {
                            if ($exifData['MimeType'] == $supportedExtension) {
                                $isSupportedImage = true;
                                break;
                            }
                        }
                    }
                }
                if ($isSupportedImage) {
                    $this->processImage($dm, $imageName, $fullImagePath, $exifData);
                } else {
                    // TODO log incorrect file extensions
                }
            }
            catch(Exception $e) {
                echo $e . PHP_EOL;
            }
        }
    }

    // Takes the first 50,000 and last 50,000 bytes of a file to generate a unique file hash
    private function getImageHash($imagePath)
    {
        $fp = fopen($imagePath, 'r');
        $data = fgets($fp, 50000);

        fseek($fp, -50000, SEEK_END);
        $data .= fgets($fp, 50000);

        return md5($data);
    }

    private function processImage($dm, $imageName, $fullImagePath, $exifData)
    {
        $fileChanged = true;
        $md5 = $this->getImageHash($fullImagePath);

        // Find the corresponding file hash in MongoDB
        $images = $dm->createQueryBuilder('AppBundle\ResourceSpace\ImageBundle\Document\Image')->field('filename')->equals($imageName)->getQuery()->execute();
        if(count($images) > 0) {
            foreach ($images as $image) {
                if($image->getHash() == $md5) {
                    $fileChanged = false;
                } else {
                    $dm->remove($image);
                    $dm->flush();
                    $dm->clear();
                }
            }
        }

        // Extract appropriate EXIF data
        $dataPid = null;
        $newData = array();
        foreach($this->exifFields as $key => $field) {
            if(array_key_exists($field['exif'], $exifData)) {
                $value = $exifData[$field['exif']];
                $newData[$field['field']] = $value;
                if ($key == 'data_pid') {
                    $dataPid = $value;
                }
            }
        }


        // Fetch the necessary data from the Datahub
        if($dataPid != null) {
            try {
                if (!$this->datahubEndpoint)
                    $this->datahubEndpoint = Endpoint::build($this->datahubUrl);

                $record = $this->datahubEndpoint->getRecord($dataPid, $this->metadataPrefix);
                $data = $record->GetRecord->record->metadata->children($this->namespace, true);
                $domDoc = new DOMDocument;
                $domDoc->loadXML($data->asXML());
                $xpath = new DOMXPath($domDoc);

                foreach ($this->dataDefinition as $dataDef) {
                    if(!array_key_exists('field', $dataDef)) {
                        continue;
                    }
                    $xpaths = array();
                    if(array_key_exists('xpaths', $dataDef)) {
                        $xpaths = $dataDef['xpaths'];
                    } else if(array_key_exists('xpath', $dataDef)) {
                        $xpaths[] = $dataDef['xpath'];
                    }
                    $value = null;
                    foreach($xpaths as $xpath_) {
                        $query = $this->buildXpath($xpath_, $this->datahubLanguage);
                        $extracted = $xpath->query($query);
                        if ($extracted) {
                            if (count($extracted) > 0) {
                                foreach ($extracted as $extr) {
                                    if ($extr->nodeValue !== 'n/a') {
                                        if($value == null) {
                                            $value = $extr->nodeValue;
                                        }
                                        else {
                                            $value .= ',' . $extr->nodeValue;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if ($value != null) {
                        $newData[$dataDef['field']] = $value;
                    }
                }
            }
            catch(OaipmhException $e) {
                echo 'Image ' . $fullImagePath . ' error: ' . $e . PHP_EOL;
            }
            catch(HttpException $e) {
                echo 'Image ' . $fullImagePath . ' error: ' . $e . PHP_EOL;
            }
        }

        $createNew = true;
        foreach($this->resourceSpaceData as $id => $resourceSpaceData) {
            // Find the matching resource
            if($resourceSpaceData['originalfilename'] == $newData['originalfilename']) {
                $createNew = false;

                // Re-upload the file if the checksums didn't match
                if($fileChanged) {
                    $this->uploadToResourceSpace($dm, $md5, $id, $imageName, $fullImagePath, false);
                }

                // Update fields in ResourceSpace where necessary
                foreach($newData as $key => $value) {
                    $update = false;
                    if(!array_key_exists($key, $resourceSpaceData)) {
                        $update = true;
                    } else {
                        if($resourceSpaceData[$key] != $value) {
                            $update = true;
                        }
                    }
                    if($update) {
                        $this->updateField($id, $key, $value);
                    }
                }
                break;
            }
        }

        // Upload a new file and set all metadata fields if this resource doesn't exist yet
        if($createNew) {
            $newId = $this->uploadToResourceSpace($dm, $md5, -1, $imageName, $fullImagePath, true);
            foreach($newData as $key => $value) {
                $this->updateField($newId, $key, $value);
            }
            //TODO log the result if something went wrong
        }

        // Delete the JPEG image we created
    }

    private function getCurrentResourceSpaceData()
    {
        $query = 'user=' . $this->apiUsername . '&function=do_search&param1=';
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $allResources = file_get_contents($url);

        if($allResources == 'Invalid signature') {
            echo 'Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into app/config/resourcespace.yml.' . PHP_EOL;
            return NULL;
        }

        $resources = json_decode($allResources, true);

        $data = array();
        foreach($resources as $resource) {
            $extracted = array('file_checksum' => $resource['file_checksum']);
            $currentData = json_decode($this->getResourceInfo($resource['ref']), true);
            foreach($currentData as $field) {
                $extracted[$field['name']] = $field['value'];
            }
            $data[$resource['ref']] = $extracted;
        }

        return $data;
    }

    private function getResourceInfo($id)
    {
        $query = 'user=' . $this->apiUsername . '&function=get_resource_field_data&param1=' . $id;
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $data = file_get_contents($url);
        return $data;
    }

    private function uploadToResourceSpace($dm, $md5, $id, $imageName, $fullImagePath, $createNew)
    {
        $result = -1;

        $pos = strrpos($imageName, '.');
        if($pos) {
            $jpegImage = substr($imageName, 0, $pos) . '.jpg';
        } else {
            $jpegImage = $imageName . '.jpg';
        }
        $imageDimensions = getimagesize($fullImagePath);
        $imageWidth = $imageDimensions[0];
        $imageHeight = $imageDimensions[1];
        try {
            $imagick = new Imagick($fullImagePath);
            echo $imagick->getImageCompression();
            $maxDimension = $this->getContainer()->getParameter('scale_image_pixels');
            if($imageWidth > $maxDimension || $imageHeight > $maxDimension) {
                $imagick->scaleImage($imageWidth >= $imageHeight ? $maxDimension : 0, $imageWidth < $imageHeight ? $maxDimension : 0);
            }
            $imagick->setFormat('jpeg');
            $imagick->writeImage($jpegImage);

            $success = false;
            if($createNew) {
                $result = $this->uploadImage(realpath($jpegImage));
                if($result > -1) {
                    $success = true;
                }
            } else {
                $success = $this->replaceImage($id, realpath($jpegImage));
            }

            if($success) {
                $newImage = new Image();
                $newImage->setFilename($imageName);
                $newImage->setHash($md5);
                $dm->persist($newImage);
                $dm->flush();
                $dm->clear();
            }
            $imagick->clear();

            if(file_exists($jpegImage)) {
                unlink($jpegImage);
            }
        } catch (Exception $e) {
            echo $e . PHP_EOL;
        }

        return $result;
    }

    private function uploadImage($image)
    {
        $query = 'user=' . $this->apiUsername . '&function=create_resource&param1=1&param2=0&param3=' . urlencode($image) . '&param4=1&param5=&param6=&param7=';
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $data = file_get_contents($url);
        return $data;
    }

    private function updateField($id, $key, $value)
    {
        $query = 'user=' . $this->apiUsername . '&function=update_field&param1=' . $id . '&param2=' . $key . '&param3=' . urlencode($value);
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $data = file_get_contents($url);
        return $data;
    }

    private function replaceImage($id, $image)
    {
        $query = 'user=' . $this->apiUsername . '&function=upload_file&param1=' . $id . '&param2=1&param3=&param4=&param5=' . urlencode($image);
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
