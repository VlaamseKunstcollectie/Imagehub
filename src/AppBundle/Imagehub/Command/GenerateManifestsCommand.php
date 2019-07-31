<?php
namespace AppBundle\Imagehub\Command;

use AppBundle\Imagehub\CanvasBundle\Document\Canvas;
use AppBundle\Imagehub\ManifestBundle\Document\Manifest;
use DOMDocument;
use DOMXPath;
use Exception;
use Phpoaipmh\Endpoint;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Debug\Exception\ContextErrorException;

class GenerateManifestsCommand extends ContainerAwareCommand
{
    private $localisations;
    private $serviceUrl;
    private $apiUrl;
    private $apiUsername;
    private $apiKey;
    private $datahubUrl;
    private $datahubLanguage;
    private $datahubLanguages;
    private $namespace;
    private $metadataPrefix;
    private $dataDefinition;
    private $datahubEndpoint;
    private $datahubUsername;
    private $datahubPassword;
    private $datahubPublicId;
    private $datahubSecret;

    // The Datahub token, requested during the first Datahub REST API call and reused for each API call after
    private $datahubToken;

    private $cantaloupeUrl;

    // The data PID's we want to generate manifests for
    private $dataPids;

    // All datahub data with the data PID's as key
    private $imagehubData;

    // All image data with the image ID's as key
    private $imageData;

    private $verbose;
    private $logger;

    // List of Datahub DOM documents and Xpath objects linked to these documents
    // These are created when requesting records from the Datahub and reused for inserting manifest and thumbnail URL's
    private $domDocs = array();
    private $xpaths = array();

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
        $this->logger = $this->getContainer()->get('logger');

        $this->datahubUrl = $input->getArgument('url');
        if(!$this->datahubUrl) {
            $this->datahubUrl = $this->getContainer()->getParameter('datahub_url');
        }
        // Username, password, public ID and secret for datahub PUT requests
        $this->datahubUsername = $this->getContainer()->getParameter('datahub_username');
        $this->datahubPassword = $this->getContainer()->getParameter('datahub_password');
        $this->datahubPublicId = $this->getContainer()->getParameter('datahub_public_id');
        $this->datahubSecret = $this->getContainer()->getParameter('datahub_secret');

        $this->verbose = $input->getOption('verbose');

        // Localisations (nl -> nl-BE, en -> en-GB etc.)
        $this->localisations = $this->getContainer()->getParameter('localisations');
        // The default Datahub language
        $this->datahubLanguage = $this->getContainer()->getParameter('datahub_language');
        // All supported Datahub languages
        $this->datahubLanguages = $this->getContainer()->getParameter('datahub_languages');

        $this->namespace = $this->getContainer()->getParameter('datahub_namespace');
        $this->metadataPrefix = $this->getContainer()->getParameter('datahub_metadataprefix');
        $this->dataDefinition = $this->getContainer()->getParameter('datahub_data_definition');
        $this->exifFields = $this->getContainer()->getParameter('exif_fields');

        // Make sure the service URL name ends with a trailing slash
        $this->serviceUrl = rtrim($this->getContainer()->getParameter('service_url'), '/') . '/';

        // Make sure the API URL does not end with a '?' character
        $this->apiUrl = rtrim($this->getContainer()->getParameter('resourcespace_api_url'), '?');
        $this->apiUsername = $this->getContainer()->getParameter('resourcespace_api_username');
        $this->apiKey = $this->getContainer()->getParameter('resourcespace_api_key');

        $this->cantaloupeUrl = $this->getContainer()->getParameter('cantaloupe_url');

        $this->generateManifests();
    }

    private function generateManifests()
    {

        $dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $dm->getDocumentCollection('ManifestBundle:Manifest')->remove([]);
        $dm->getDocumentCollection('CanvasBundle:Canvas')->remove([]);

        $this->getResourceSpaceData();
        $this->addCantaloupeData();
        $this->addDatahubData();
        $this->addAllRelations();
        $this->fixSortOrders();
        $this->addArthubRelations();
        $this->generateAndStoreManifests($dm);
    }

    private function getResourceInfo($id)
    {
        $query = 'user=' . $this->apiUsername . '&function=get_resource_field_data&param1=' . $id;
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $data = file_get_contents($url);
        return json_decode($data);
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

    private function getResourceSpaceData()
    {
        $query = 'user=' . $this->apiUsername . '&function=do_search&param1=';
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $allResources = file_get_contents($url);
        $resources = json_decode($allResources, true);

        $this->dataPids = array();
        $this->imagehubData = array();
        $this->imageData = array();
        foreach($resources as $resource) {
            $currentData = $this->getResourceInfo($resource['ref']);
            $newDatahubData = array(
                'related'       => '',
                'data_pid'      => '',
                'related_works' => array(),
                'image_ids'     => array()
            );

            $dataPid = null;
            $imageId = null;
            foreach($currentData as $data) {
                if($data->name == 'pidafbeelding') {
                    $newDatahubData['data_pid'] = $data->value;
                    $dataPid = $data->value;
                } else if($data->name == 'originalfilename') {
                    $newDatahubData['image_ids'][] = $data->value;
                    $imageId = $data->value;
                }
            }

            if($this->verbose) {
                $this->logger->info('Retrieved ' . $dataPid . ' from ResourceSpace');
            }

            $newDatahubData['manifest_id'] = $this->extractManifestId($dataPid);

            // Add related works if this dataPid is already present in the image data
            if(array_key_exists($dataPid, $this->imagehubData)) {
                $this->imagehubData[$dataPid]['image_ids'][] = $imageId;
            } else {
                $this->imagehubData[$dataPid] = $newDatahubData;
            }
            if(!in_array($dataPid, $this->dataPids)) {
                $this->dataPids[] = $dataPid;
            }
            $this->imageData[$imageId] = array();
        }

        // Sort image ID's in ascending order
        foreach($this->imagehubData as $dataPid => $data) {
            sort($this->imagehubData[$dataPid]['image_ids']);
        }
    }

    // Generates manifest ID's based on institution + work ID
    private function extractManifestId($dataPid)
    {
        $expl = explode(':', $dataPid);
        $manifestId = '';
        for($i = 2; $i < count($expl); $i++) {
            $manifestId .= (empty($manifestId) ? '' : ':') . $expl[$i];
        }
        return $manifestId;
    }

    private function addCantaloupeData()
    {
        foreach($this->imageData as $imageId => $imageData) {
            try {
                $jsonData = file_get_contents($this->cantaloupeUrl . $imageId . '/info.json');
                $data = json_decode($jsonData);
                $this->imageData[$imageId]['height'] = $data->height;
                $this->imageData[$imageId]['width'] = $data->width;
                if($this->verbose) {
                    $this->logger->info('Retrieved image ' . $imageId . ' from Cantaloupe');
                }
            } catch(Exception $e) {
                $this->logger->error($e->getMessage());
                // TODO proper error reporting
            }
        }
    }

    private function addDatahubData()
    {
        try {
            // Fetch the necessary data from the Datahub
            if (!$this->datahubEndpoint)
                $this->datahubEndpoint = Endpoint::build($this->datahubUrl . '/oai');

            foreach($this->imagehubData as $dataPid => $value) {
                try {
                    $this->addDatahubDataToImage($dataPid);
                    if($this->verbose) {
                        $this->logger->info('Added Datahub data to ' . $dataPid);
                    }
                }
                catch(Exception $e) {
                    unset($this->imagehubData[$dataPid]);
                    if($this->verbose) {
                        $this->logger->error($e);
                    } else {
                        $this->logger->error($e->getMessage());
                    }
                }
            }
        }
        catch(Exception $e) {
            if($this->verbose) {
                $this->logger->error($e);
            } else {
                $this->logger->error($e->getMessage());
            }
        }
    }

    private function addDatahubDataToImage($dataPid)
    {
        $record = $this->datahubEndpoint->getRecord($dataPid, $this->metadataPrefix);
        $data = $record->GetRecord->record->metadata->children($this->namespace, true);
        $domDoc = new DOMDocument;
        $domDoc->preserveWhiteSpace = false;
        $domDoc->formatOutput = true;
        $domDoc->loadXML($data->asXML());
        $xpath = new DOMXPath($domDoc);

        // Find all related works (hasPart, isPartOf, relatedTo)
        $query = $this->buildXpath('descriptiveMetadata[@xml:lang="{language}"]/objectRelationWrap/relatedWorksWrap/relatedWorkSet', $this->datahubLanguage);
        $domNodes = $xpath->query($query);
        $value = null;
        if ($domNodes) {
            if (count($domNodes) > 0) {
                foreach ($domNodes as $domNode) {
                    $relatedDataPid = null;
                    $relation = null;
                    $sortOrder = 1;
                    if($domNode->attributes) {
                        for($i = 0; $i < $domNode->attributes->length; $i++) {
                            if($domNode->attributes->item($i)->nodeName == $this->namespace . ':sortorder') {
                                $sortOrder = $domNode->attributes->item($i)->nodeValue;
                            }
                        }
                    }
                    $childNodes = $domNode->childNodes;
                    foreach ($childNodes as $childNode) {
                        if ($childNode->nodeName == $this->namespace . ':relatedWork') {
                            $objects = $childNode->childNodes;
                            foreach($objects as $object) {
                                if($object->childNodes) {
                                    foreach($object->childNodes as $objectId) {
                                        if($objectId->attributes) {
                                            for($i = 0; $i < $objectId->attributes->length; $i++) {
                                                if($objectId->attributes->item($i)->nodeName == $this->namespace . ':type' && $objectId->attributes->item($i)->nodeValue == 'oai') {
                                                    $relatedDataPid = $objectId->nodeValue;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        } else if($childNode->nodeName == $this->namespace . ':relatedWorkRelType') {
                            $objects = $childNode->childNodes;
                            foreach($objects as $object) {
                                if($object->nodeName == $this->namespace . ':conceptID') {
                                    $relation = substr($object->nodeValue, strrpos($object->nodeValue, '/') + 1);
                                }
                            }
                        }
                    }
                    if($relatedDataPid != null) {
                        if($relation == null) {
                            $relation = 'relation';
                        }
                        $arr = array(
                            'related_work_type' => $relation,
                            'data_pid'          => $relatedDataPid,
                            'sort_order'        => $sortOrder
                        );
                        $this->imagehubData[$dataPid]['related_works'][$relatedDataPid] = $arr;

                        // If we don't have any datahub data on this data PID yet, fetch and add it
                        // Set manifest id to empty string because we won't generate a manifest for this one,
                        // since we don't have any image data on it
                        // We're mostly just interested in its related works
                        if(!array_key_exists($relatedDataPid, $this->imagehubData)) {
                            $newDatahubData = array(
                                'related'       => '',
                                'data_pid'      => $relatedDataPid,
                                'related_works' => array(),
                                'image_ids'     => array(),
                                'manifest_id'   => ''
                            );
                            $this->imagehubData[$relatedDataPid] = $newDatahubData;
                            $this->addDatahubDataToImage($relatedDataPid);
                        }
                    }
                }
            }
        }

        // All all (multilingual) metadata along with title and description
        $this->imagehubData[$dataPid]['metadata'] = array();
        foreach($this->datahubLanguages as $language) {
            foreach ($this->dataDefinition as $key => $dataDef) {
                if(!array_key_exists('label', $dataDef)) {
                    continue;
                }
                $query = $this->buildXpath($dataDef['xpath'], $language);
                $extracted = $xpath->query($query);
                $value = null;
                if ($extracted) {
                    if (count($extracted) > 0) {
                        foreach ($extracted as $extr) {
                            if ($extr->nodeValue !== 'n/a') {
                                $value = $extr->nodeValue;
                            }
                        }
                    }
                }
                if ($value != null) {
                    if(array_key_exists('label', $dataDef)) {
                        if (!array_key_exists($dataDef['label'], $this->imagehubData[$dataPid]['metadata'])) {
                            $this->imagehubData[$dataPid]['metadata'][$dataDef['label']] = array();
                        }
                        $this->imagehubData[$dataPid]['metadata'][$dataDef['label']][$language] = $value;
                    }
                }
            }
        }

        $this->domDocs[$dataPid] = $domDoc;
        $this->xpaths[$dataPid] = $xpath;
    }

    private function addAllRelations()
    {
        $relations = array();

        // Initialize the array containing all directly related works
        foreach($this->imagehubData as $dataPid => $value) {
            $relations[$dataPid] = $value['related_works'];
        }

        // Loop through all data pids and keep adding relations until all (directly or indirectly) related works contain references to each other
        $relationsChanged = true;
        while($relationsChanged) {
            $relationsChanged = false;
            foreach($relations as $dataPid => $related) {
                foreach($relations as $otherPid => $otherRelation) {
                    if(array_key_exists($dataPid, $otherRelation)) {
                        foreach ($related as $relatedData) {
                            if (!array_key_exists($relatedData['data_pid'], $otherRelation)) {
                                $relations[$otherPid][$relatedData['data_pid']] = array(
                                    'related_work_type' => 'relation',
                                    'data_pid'          => $relatedData['data_pid'],
                                    'sort_order'        => $relatedData['sort_order']
                                );
                                $relationsChanged = true;
                            }
                        }
                    }
                }
            }
        }

        // Add the newly found relations to the appropriate related_works arrays
        foreach($relations as $dataPid => $related) {
            foreach($related as $relatedData) {
                if(array_key_exists($relatedData['data_pid'], $this->imagehubData)) {
                    if (array_key_exists($dataPid, $this->imagehubData)) {
                        if (!array_key_exists($relatedData['data_pid'], $this->imagehubData[$dataPid]['related_works'])) {
                            $this->imagehubData[$dataPid]['related_works'][$relatedData['data_pid']] = array(
                                'related_work_type' => 'relation',
                                'data_pid'          => $relatedData['data_pid'],
                                'sort_order'        => $relatedData['sort_order']
                            );
                        }
                    }
                }
            }
        }

        // Add reference to itself
        foreach($this->imagehubData as $dataPid => $value) {
            if (!array_key_exists($dataPid, $value['related_works'])) {
                $this->imagehubData[$dataPid]['related_works'][$dataPid] = array(
                    'related_work_type' => 'relation',
                    'data_pid'          => $dataPid,
                    'sort_order'        => 1
                );
            }
        }
    }

    private function isHigherOrder($type, $highestType)
    {
        if($highestType == null) {
            return true;
        } else if($highestType == 'isPartOf') {
            return false;
        } else if($highestType == 'relation') {
            return $type == 'isPartOf';
        } else if($highestType == 'hasPart') {
            return $type == 'isPartOf' || $type == 'relation';
        } else {
            return true;
        }
    }

    private function fixSortOrders()
    {
        foreach($this->imagehubData as $dataPid => $value) {
            if(count($value['related_works']) > 1) {

                // Sort based on data pids to ensure all related_works for related data pid's contain exactly the same information in the same order
                ksort($this->imagehubData[$dataPid]['related_works']);

                // Check for colliding sort orders
                $mismatch = true;
                while($mismatch) {
                    $mismatch = false;
                    foreach ($this->imagehubData[$dataPid]['related_works'] as $pid => $relatedWork) {
                        $order = $this->imagehubData[$dataPid]['related_works'][$pid]['sort_order'];

                        foreach ($this->imagehubData[$dataPid]['related_works'] as $otherPid => $otherWork) {

                            // Find colliding sort orders
                            if ($pid != $otherPid && $this->imagehubData[$dataPid]['related_works'][$otherPid]['sort_order'] == $order) {

                                // Upon collision, find out which relation has the highest priority
                                $highest = null;
                                $highestType = 'none';
                                foreach ($this->imagehubData[$dataPid]['related_works'] as $relatedPid => $data) {
                                    if ($this->imagehubData[$dataPid]['related_works'][$relatedPid]['sort_order'] == $order
                                        && $this->isHigherOrder($this->imagehubData[$dataPid]['related_works'][$relatedPid]['related_work_type'], $highestType)) {
                                        $highest = $relatedPid;
                                        $highestType = $this->imagehubData[$dataPid]['related_works'][$relatedPid]['related_work_type'];
                                    }
                                }

                                // Increment the sort order of all related works with the same or higher sort order with one,
                                // except the one with the highest priority
                                foreach ($this->imagehubData[$dataPid]['related_works'] as $relatedPid => $data) {
                                    if ($relatedPid != $highest && $this->imagehubData[$dataPid]['related_works'][$relatedPid]['sort_order'] >= $order) {
                                        $this->imagehubData[$dataPid]['related_works'][$relatedPid]['sort_order'] = $this->imagehubData[$dataPid]['related_works'][$relatedPid]['sort_order'] + 1;
                                    }
                                }


                                $mismatch = true;
                                break;
                            }
                        }
                    }
                }

                // Sort related works based on sort_order
                uasort($this->imagehubData[$dataPid]['related_works'], array('AppBundle\Imagehub\Command\GenerateManifestsCommand', 'sortRelatedWorks'));
            }
        }
    }

    private function sortRelatedWorks($a, $b)
    {
        return $a['sort_order'] - $b['sort_order'];
    }

    // Filter out the .be in manifest ID's
    private function filterDotBe($manifestId)
    {
        $expl = explode(':', $manifestId);
        $newManifestId = '';
        $dotBeIndex = strpos($expl[0], '.be');
        if($dotBeIndex > 0) {
            $newManifestId = substr($expl[0], 0, $dotBeIndex);
        } else {
            $newManifestId = $expl[0];
        }
        for($i = 1; $i < count($expl); $i++) {
            $newManifestId .= (empty($newManifestId) ? '' : ':') . $expl[$i];
        }
        return $newManifestId;
    }

    private function addArthubRelations()
    {
        foreach($this->imagehubData as $dataPid => $value) {
            $this->imagehubData[$dataPid]['related'] = 'https://arthub.vlaamsekunstcollectie.be/nl/catalog/' . $this->filterDotBe($value['manifest_id']);
        }
        return $this->imagehubData;
    }

    private function generateAndStoreManifests($dm)
    {
        $validate = $this->getContainer()->getParameter('validate_manifests');
        $validatorUrl = $this->getContainer()->getParameter('validator_url');

        // Top-level collection containing a link to all manifests
        $manifests = array();

        foreach($this->dataPids as $dataPid) {
            if(!array_key_exists($dataPid, $this->imagehubData)) {
                continue;
            }

            $data = $this->imagehubData[$dataPid];
            $label = array();
            $description = array();
            $attribution = array();

            // Fill in (multilingual) manifest data
            $manifestMetadata = array();
            foreach($data['metadata'] as $key => $metadata) {
                $arr = array();
                foreach($metadata as $language => $value) {
                    // Change nl into nl-BE, en into en-GB, etc.
                    if(array_key_exists($language, $this->localisations)) {
                        $language = $this->localisations[$language];
                    }
                    $arr[] = array(
                        '@language' => $language,
                        '@value'    => $value
                    );
                }
                // Grab the values for the top-level description, label and attribution
                if($key == 'Description') {
                    $description = $arr;
                    // Description is not included in the metadata field
                    continue;
                } else if($key == 'Title') {
                    $label = $arr;
                } else if($key == 'Credit Line') {
                    $attribution = $arr;
                }
                $manifestMetadata[] = array(
                    'label' => $key,
                    'value' => $arr
                );
            }

            // Generate the canvases
            $canvases = array();
            $index = 0;
            $startCanvas = null;
            $thumbnail = null;

            // Loop through all works related to this data PID (including itself)
            foreach($data['related_works'] as $relatedDataPid => $relatedData) {

                // When the related data pid is the data pid of the record we're currently processing,
                // we know that this canvas is in fact the main canvas
                $isStartCanvas = $relatedDataPid == $dataPid;

                // Loop through all image ID's linked to this data PID
                foreach($this->imagehubData[$relatedDataPid]['image_ids'] as $imageId) {
                    $index++;
                    $canvasId = $this->serviceUrl . $data['manifest_id'] . '/canvas/' . $index . '.json';
                    $serviceId = $this->serviceUrl . $imageId;
                    if($isStartCanvas && $startCanvas == null) {
                        $startCanvas = $canvasId;
                        $thumbnail = $serviceId;
                    }
                    $service = array(
                        '@context' => 'http://iiif.io/api/image/2/context.json',
                        '@id'      => $serviceId,
                        'profile'  => 'http://iiif.io/api/image/2/level2.json'
                    );
                    $resource = array(
                        '@id'     => $this->serviceUrl . $imageId . '/full/full/0/default.jpg',
                        '@type'   => 'dctypes:Image',
                        'format'  => 'image/jpeg',
                        'service' => $service,
                        'height'  => $this->imageData[$imageId]['height'],
                        'width'   => $this->imageData[$imageId]['width']
                    );
                    $image = array(
                        '@context'   => 'http://iiif.io/api/presentation/2/context.json',
                        '@type'      => 'oa:Annotation',
                        '@id'        => $canvasId . '/image',
                        'motivation' => 'sc:painting',
                        'resource'   => $resource,
                        'on'         => $canvasId
                    );
                    $newCanvas = array(
                        '@id'    => $canvasId,
                        '@type'  => 'sc:Canvas',
                        'label'  => $imageId,
                        'height' => $this->imageData[$imageId]['height'],
                        'width'  => $this->imageData[$imageId]['width'],
                        'images' => array($image)
                    );
                    $canvases[] = $newCanvas;

                    // Store the canvas in mongodb
                    $canvasDocument = new Canvas();
                    $canvasDocument->setCanvasId($canvasId);
                    $canvasDocument->setData(json_encode($newCanvas));
                    $dm->persist($canvasDocument);
                }
            }

            // Fill in sequence data
            if($startCanvas == null) {
                $manifestSequence = array(
                    '@type'       => 'sc:Sequence',
                    '@context'    => 'http://iiif.io/api/presentation/2/context.json',
                    'canvases'    => $canvases
                );
            } else {
                $manifestSequence = array(
                    '@type'       => 'sc:Sequence',
                    '@context'    => 'http://iiif.io/api/presentation/2/context.json',
                    'startCanvas' => $startCanvas,
                    'canvases'    => $canvases
                );
            }

            $manifestId = $this->serviceUrl . $data['manifest_id'] . '/manifest.json';
            // Generate the whole manifest
            $manifest = array(
                '@context'         => 'http://iiif.io/api/presentation/2/context.json',
                '@type'            => 'sc:Manifest',
                '@id'              => $manifestId,
                'label'            => $label,
                'attribution'      => $attribution,
                'related'          => $data['related'],
                'description'      => empty($description) ? 'n/a' : $description,
                'metadata'         => $manifestMetadata,
                'viewingDirection' => 'left-to-right',
                'viewingHint'      => 'individuals',
                'sequences'        => array($manifestSequence),
            );

            // Store the manifest in mongodb
            $manifestDocument = new Manifest();
            $manifestDocument->setManifestId($manifestId);
            $manifestDocument->setData(json_encode($manifest));
            $dm->persist($manifestDocument);
            $dm->flush();


            // Validate the manifest
            // We can only pass a URL to the validator, so the manifest needs to be stored and served already before validation
            // If it does not pass validation, remove from the database
            $valid = true;
            if($validate) {
                $valid = $this->validateManifest($validatorUrl, $manifestId);
                if (!$valid) {
                    $this->logger->error('Manifest ' . $manifestId . ' is not valid.');
                    $dm->remove($manifestDocument);
                    $dm->flush();
                }
            }
            $dm->clear();

            if($valid) {
                if($this->verbose) {
                    $this->logger->info('Generated manifest ' . $manifestId . ' for data pid ' . $dataPid);
                }

                // Add to manifests array to add to the top-level collection
                $manifests[] = array(
                    '@id' => $manifestId,
                    '@type' => 'sc:Manifest',
                    'label' => $label
                );

                // Update the LIDO data to include the manifest and thumbnail
                $this->addManifestAndThumbnailToLido($this->namespace, $dataPid, $manifestId, $thumbnail);
            }
        }

        // Generate the top-level collection and store it in mongoDB
        $collectionId = $this->serviceUrl . 'collection/top';
        $collection = array(
            '@context' => 'http://iiif.io/api/presentation/2/context.json',
            '@id' => $collectionId,
            '@type' => 'sc:Collection',
            'label' => 'Top Level Collection for Imagehub',
            'viewingHint' => 'top',
            'description' => 'This collection lists all the IIIF manifests available in this Imagehub instance',
            'manifests' => $manifests
        );
        $manifestDocument = new Manifest();
        $manifestDocument->setManifestId($collectionId);
        $manifestDocument->setData(json_encode($collection));
        $dm->persist($manifestDocument);
        $dm->flush();

        $valid = true;
        if($validate) {
            $valid = $this->validateManifest($validatorUrl, $collectionId);
            if (!$valid) {
                $this->logger->error('Top-level collection ' . $collectionId . ' is not valid.');
                $dm->remove($manifestDocument);
                $dm->flush();
            }
        }
        $dm->clear();

        if($this->verbose) {
            if ($valid) {
                $this->logger->info('Created and stored top-level collection');
            }
            $this->logger->info('Done, created and stored ' . $manifests . ' manifests.');
        }
    }

    private function validateManifest($validatorUrl, $manifestId)
    {
        $valid = true;
        try {
            $validatorJsonResult = file_get_contents($validatorUrl . $manifestId);
            $validatorResult = json_decode($validatorJsonResult);
            $valid = $validatorResult->okay == 1;
            if (!empty($validatorResult->warnings)) {
                foreach ($validatorResult->warnings as $warning) {
                    $this->logger->warning('Manifest ' . $manifestId . ' warning: ' . $warning);
                }
            }
            if (!empty($validatorResult->error)) {
                if ($validatorResult->error != 'None') {
                    $valid = false;
                    $this->logger->error('Manifest ' . $manifestId . ' error: ' . $validatorResult->error);
                }
            }
        } catch (Exception $e) {
            if($this->verbose) {
                $this->logger->error('Error validating manifest ' . $manifestId . ': ' . $e);
            } else {
                $this->logger->error('Error validating manifest ' . $manifestId . ': ' . $e->getMessage());
            }
        }
        return $valid;
    }

    private function addManifestAndThumbnailToLido($namespace, $dataPid, $manifestUrl, $thumbnail)
    {
        if(!array_key_exists($dataPid, $this->domDocs) || !array_key_exists($dataPid, $this->xpaths)) {
            return;
        }
        $domDoc = $this->domDocs[$dataPid];
        $xpath = $this->xpaths[$dataPid];

        $query = 'descendant::lido:administrativeMetadata';
        $administrativeMetadatas = $xpath->query($query);
        foreach($administrativeMetadatas as $administrativeMetadata) {

            $resourceWrap = null;
            foreach($administrativeMetadata->childNodes as $childNode) {
                if ($childNode->nodeName == $namespace . ':resourceWrap') {
                    $resourceWrap = $childNode;

                    $domElemsToRemove = array();
                    // Remove any resourceSets that already contain a manifest URL
                    foreach($childNode->childNodes as $resourceSet) {
                        if($resourceSet->nodeName == $namespace . ':resourceSet') {
                            $remove = false;
                            foreach($resourceSet->childNodes as $resource) {
                                if($resource->getAttribute($namespace . ':source') == 'Imagehub') {
                                    $remove = true;
                                    break;
                                }
                            }
                            if($remove) {
                                $domElemsToRemove[] = $resourceSet;
                            }
                        }
                    }
                    foreach($domElemsToRemove as $resourceSet) {
                        $childNode->removeChild($resourceSet);
                    }
                    break;
                }
            }

            if ($resourceWrap == null) {
                $resourceWrap = $domDoc->createElement($namespace . ':resourceWrap');
                $administrativeMetadata->appendChild($resourceWrap);
            }

            // Add manifest URL to the administrative metadata
            $resourceSet = $domDoc->createElement($namespace . ':resourceSet');
            $resourceWrap->appendChild($resourceSet);

            $resourceId = $domDoc->createElement($namespace . ':resourceID');
            $resourceId->setAttribute($namespace . ':type', 'purl');
            $resourceId->setAttribute($namespace . ':source', 'Imagehub');
            $resourceId->nodeValue = $manifestUrl;
            $resourceSet->appendChild($resourceId);

            $resourceType = $domDoc->createElement($namespace . ':resourceType');
            $resourceSet->appendChild($resourceType);
            $term = $domDoc->createElement($namespace . ':term');
            $term->setAttribute($namespace . ':pref', 'preferred');
            $term->nodeValue = 'IIIF Manifest';
            $resourceType->appendChild($term);

            $resourceSource = $domDoc->createElement($namespace . ':resourceSource');
            $resourceSet->appendChild($resourceSource);
            $legalBodyName = $domDoc->createElement($namespace . ':legalBodyName');
            $resourceSource->appendChild($legalBodyName);
            $appellationValue = $domDoc->createElement($namespace . ':appellationValue');
            // Hardcoded value
            $appellationValue->nodeValue = 'Vlaamse Kunstcollectie VZW';
            $legalBodyName->appendChild($appellationValue);

            // Add thumbnail to the administrative metadata
            $resourceSet = $domDoc->createElement($namespace . ':resourceSet');
            $resourceWrap->appendChild($resourceSet);

            $resourceId = $domDoc->createElement($namespace . ':resourceID');
            $resourceId->setAttribute($namespace . ':type', 'purl');
            $resourceId->setAttribute($namespace . ':source', 'Imagehub');
            $resourceId->nodeValue = $thumbnail;
            $resourceSet->appendChild($resourceId);

            $resourceType = $domDoc->createElement($namespace . ':resourceType');
            $resourceSet->appendChild($resourceType);
            $term = $domDoc->createElement($namespace . ':term');
            $term->setAttribute($namespace . ':pref', 'preferred');
            $term->nodeValue = 'thumbnail';
            $resourceType->appendChild($term);
        }

        if($this->datahubToken == null) {
            $this->initializeDatahubToken();
        }

        $this->updateDatahubRecord($dataPid, $domDoc->saveXML());
    }

    private function initializeDatahubToken()
    {
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, $this->datahubUrl . '/oauth/v2/token');
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS,
            'grant_type=password&username=' . urlencode($this->datahubUsername)
            . '&password=' . urlencode($this->datahubPassword)
            . '&client_id=' . urlencode($this->datahubPublicId)
            . '&client_secret=' . urlencode($this->datahubSecret)
        );

        $resultJson = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($resultJson);
        $this->datahubToken = $result->access_token;
        if($this->verbose) {
            $this->logger->info('Datahub token: ' . $this->datahubToken);
        }
    }

    private function updateDatahubRecord($dataPid, $xmlData)
    {
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, $this->datahubUrl . '/api/v1/data/' . $dataPid . '.lidoxml');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $xmlData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/lido+xml',
                'Content-Length: ' . strlen($xmlData),
                'Authorization: Bearer ' . $this->datahubToken
            )
        );

        $result = curl_exec($ch);

        $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if(!empty($result) || $responseCode != 204) {
            $this->logger->error('Error updating Datahub record ' . $dataPid . ': ' . PHP_EOL . $result);
        } else if($this->verbose) {
            $this->logger->info('Updated Datahub record ' . $dataPid);
        }

        curl_close($ch);
    }
}
