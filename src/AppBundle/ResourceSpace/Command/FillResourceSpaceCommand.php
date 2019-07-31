<?php
namespace AppBundle\ResourceSpace\Command;

use DOMDocument;
use DOMXPath;
use Exception;
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
    private $verbose;
    private $logger;

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
        $this->logger = $this->getContainer()->get('logger');

        $folder = $input->getArgument('folder');
        if (!$folder) {
            $folder = $this->getContainer()->getParameter('images_folder');
        }
        $this->datahubUrl = $input->getArgument('url');
        if(!$this->datahubUrl) {
            $this->datahubUrl = $this->getContainer()->getParameter('datahub_url');
        }
        $this->verbose = $input->getOption('verbose');

        $this->datahubLanguage = $this->getContainer()->getParameter('datahub_language');
        $this->namespace = $this->getContainer()->getParameter('datahub_namespace');
        $this->metadataPrefix = $this->getContainer()->getParameter('datahub_metadataprefix');
        $this->dataDefinition = $this->getContainer()->getParameter('datahub_data_definition');
        $this->exifFields = $this->getContainer()->getParameter('exif_fields');

        // Make sure the folder name ends with a trailing slash
        $folder = rtrim($folder, '/') . '/';

        $supportedExtensions = $this->getContainer()->getParameter('supported_extensions');
        $supportedCompressions = $this->getContainer()->getParameter('supported_compressions');

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
        $totalImages = 0;
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
                $isSupportedCompression = false;

                if(strpos($imageName, '.') > -1) {
                    $this->logger->error('Error: file ' . $imageName . ' has a filename extension.');
                    continue;
                }


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
                    if(array_key_exists('Compression', $exifData)) {
                        foreach ($supportedCompressions as $supportedCompression) {
                            if ($exifData['Compression'] == $supportedCompression) {
                                $isSupportedCompression = true;
                                break;
                            }
                        }
                    }
                }

                if (!$isSupportedImage) {
                    $this->logger->error('Error: file ' . $imageName . ' does not have the correct extension.');
                }
                if(!$isSupportedCompression) {
                    $this->logger->error('Error: file ' . $imageName . ' has the wrong image compression.');
                }

                if($isSupportedImage && $isSupportedCompression) {
                    if($this->verbose) {
                        $this->logger->info('Processing ' . $imageName);
                    }
                    $this->processImage($dm, $imageName, $fullImagePath, $exifData);
                    $totalImages++;
                }
            }
            catch(Exception $e) {
                $this->logger->error($e);
            }
        }
        if($this->verbose) {
            $this->logger->info('Done, processed ' . $totalImages . ' total images');
        }
    }

    // Takes the first 50,000 bytes of a file to generate a unique file hash
    private function getImageHash($imagePath)
    {
        $fp = fopen($imagePath, 'r');
        $data = fgets($fp, 50000);

        return md5($data);
    }

    private function processImage($dm, $imageName, $fullImagePath, $exifData)
    {
        $md5 = $this->getImageHash($fullImagePath);

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

                foreach ($this->dataDefinition as $key => $dataDef) {
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
                                        else if($key != 'keywords' || !in_array($extr->nodeValue, explode(",", $value))) {
                                            $value .= ',' . $extr->nodeValue;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if ($value != null) {
                        // Nginx returns a 301 if the request URL becomes too long, so we need to cut it down
                        $newData[$dataDef['field']] = trim(strlen($value) > 780 ? substr($value, 0, 780) : $value);
                    }
                }
            }
            catch(OaipmhException $e) {
                $this->logger->error('Image ' . $fullImagePath . ' error: ' . $e);
            }
            catch(HttpException $e) {
                $this->logger->error('Image ' . $fullImagePath . ' error: ' . $e);
            }
        } else {
            $this->logger->error('Error: no data pid set on image ' . $fullImagePath);
        }

        $createNew = true;
        foreach($this->resourceSpaceData as $id => $resourceSpaceData) {
            // Find the matching resource
            if($resourceSpaceData['originalfilename'] == $newData['originalfilename']) {
                $createNew = false;

                $fileChanged = $resourceSpaceData['file_checksum'] != $md5;
                // Re-upload the file if the checksums didn't match
                if($fileChanged) {
                    $this->uploadToResourceSpace($dm, $md5, $id, $imageName, $fullImagePath, false);
                }

                // Update fields in ResourceSpace where necessary
                $updatedFields = 0;
                foreach($newData as $key => $value) {
                    $update = false;
                    if(!array_key_exists($key, $resourceSpaceData)) {
                        if($this->verbose) {
                            $this->logger->error('Field ' . $key . ' does not exist, should be ' . $value);
                        }
                        $update = true;
                    } else if($key == 'keywords') {
                        $explodeVal = explode(',', $value);
                        $explodeRS = explode(',', $resourceSpaceData[$key]);
                        $hasAll = true;
                        foreach($explodeVal as $val) {
                            $has = false;
                            foreach($explodeRS as $rs) {
                                if($rs == $val) {
                                    $has = true;
                                    break;
                                }
                            }
                            if(!$has) {
                                $hasAll = false;
                                break;
                            }
                        }
                        if(!$hasAll) {
                            if($this->verbose) {
                                $this->logger->error('Mismatching field ' . $key . ', should be ' . $value . ', is ' . $resourceSpaceData[$key]);
                            }
                            $update = true;
                        }
                    } else {
                        if($resourceSpaceData[$key] != $value) {
                            if($this->verbose) {
                                $this->logger->error('Mismatching field ' . $key . ', should be ' . $value . ', is ' . $resourceSpaceData[$key]);
                            }
                            $update = true;
                        }
                    }
                    if($update) {
                        $result = $this->updateField($id, $key, $value);
                        if($result !== 'true') {
                            $this->logger->error('Error updating field ' . $key . ' for image ' . $imageName . ':' . PHP_EOL . $result);
                            //TODO log when something went wrong
                        } else {
                            $updatedFields++;
                        }
                    }
                }
                if($this->verbose) {
                    if($fileChanged && $updatedFields > 0) {
                        $this->logger->info('Replaced image ' . $fullImagePath . ', updated ' . $updatedFields . ' fields');
                    } else if($fileChanged) {
                        $this->logger->info('Replaced image ' . $fullImagePath);
                    } else if($updatedFields > 0) {
                        $this->logger->info('Updated ' . $updatedFields . ' fields for image ' . $fullImagePath);
                    }
                }
                break;
            }
        }

        // Upload a new file and set all metadata fields if this resource doesn't exist yet
        if($createNew) {
            $newId = $this->uploadToResourceSpace($dm, $md5, -1, $imageName, $fullImagePath, true);
            if(preg_match('/^[0-9]+$/', $newId)) {
                $fieldsAdded = 0;
                foreach($newData as $key => $value) {
                    $result = $this->updateField($newId, $key, $value);
                    if($result !== 'true') {
                        $this->logger->error('Error adding field ' . $key . ' for image ' . $imageName . ':' . PHP_EOL . $result);
                        //TODO log the result if something went wrong
                    } else {
                        $fieldsAdded++;
                    }
                }
                if($this->verbose) {
                    $this->logger->info('Uploaded image ' . $fullImagePath . ' to ResourceSpace, added ' . $fieldsAdded . ' fields');
                }
            } else {
                $this->logger->error('Error creating resource ' . $imageName . ': ' . PHP_EOL . $newId);
            }
        }
    }

    private function getCurrentResourceSpaceData()
    {
        $query = 'user=' . $this->apiUsername . '&function=do_search&param1=';
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $allResources = file_get_contents($url);

        if($allResources == 'Invalid signature') {
            $this->logger->error('Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into app/config/parameters.yml.');
            return NULL;
        }

        $resources = json_decode($allResources, true);
        $data = array();
        foreach($resources as $resource) {
            $extracted = array();
            $currentData = json_decode($this->getResourceInfo($resource['ref']), true);
            if($currentData == null) {
                continue;
            }
            if(empty($currentData)) {
                continue;
            }
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

        try {
            $success = false;
            if($createNew) {
                $result = $this->uploadImage(realpath($fullImagePath));
                if($result > -1) {
                    $success = true;
                }
            } else {
                $success = $this->replaceImage($id, realpath($fullImagePath));
            }

        } catch (Exception $e) {
            $this->logger->error($e);
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
