<?php
namespace AppBundle\ImageHub\Command;

use AppBundle\ImageHub\ManifestBundle\Document\Manifest;
use Couchbase\Document;
use DOMDocument;
use DOMXPath;
use Exception;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\OaipmhException;
use Stubs\DocumentManager;
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
    private $datahubLanguage;
    private $datahubLanguages;
    private $namespace;
    private $metadataPrefix;
    private $dataDefinition;
    private $datahubEndpoint;
    private $cantaloupeUrl;

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
        // The default Datahub language
        $this->datahubLanguage = $this->getContainer()->getParameter('datahub.language');
        // All supported Datahub languages
        $this->datahubLanguages = $this->getContainer()->getParameter('datahub.languages');

        $this->namespace = $this->getContainer()->getParameter('datahub.namespace');
        $this->metadataPrefix = $this->getContainer()->getParameter('datahub.metadataprefix');
        $this->dataDefinition = $this->getContainer()->getParameter('datahub.data_definition');
        $this->exifFields = $this->getContainer()->getParameter('exif_fields');

        // Make sure the API URL does not end with a '?' character
        $this->apiUrl = rtrim($this->getContainer()->getParameter('api_url'), '?');
        $this->apiUsername = $this->getContainer()->getParameter('api_username');
        $this->apiKey = $this->getContainer()->getParameter('api_key');

        $this->cantaloupeUrl = $this->getContainer()->getParameter('cantaloupe_url');

        $this->generateAndStoreManifests();
    }

    private function generateAndStoreManifests()
    {

        $dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $dm->getDocumentCollection('ManifestBundle:Manifest')->remove([]);

        $imageData = $this->getResourceSpaceData();
        $this->addCantaloupeData($imageData);
        $this->addDatahubData($imageData);
        $this->addAllRelations($imageData);
        $this->addArthubRelations($imageData);
//        var_dump($imageData);
        $manifests = $this->generateManifests($imageData);
        $this->storeManifests($manifests, $dm);

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

        $imageData = array();
        // Test data
        $imageData = array(
            'oai:datahub.vlaamsekunstcollectie.be:kmska.be:523' =>
            array(
                'label'         => 'derp',
                'attribution'   => 'MSK',
                'related'       => 'arthub ID',
                'description'   => 'a description',
                'data_pid'      => 'oai:datahub.vlaamsekunstcollectie.be:kmska.be:523',
                'image_id'      => '1000006969.tif',
                'related_works' => array(),
                'sort_order'    => 2,
                'manifest_id'   => 'kmksa.be:523',
                'height'        => 1337,
                'width'         => 69
            ),
            'oai:datahub.vlaamsekunstcollectie.be:kmska.be:524' =>
                array(
                    'label'         => 'derp',
                    'attribution'   => 'MSK',
                    'related'       => 'arthub ID',
                    'description'   => 'a description',
                    'data_pid'      => 'oai:datahub.vlaamsekunstcollectie.be:kmska.be:524',
                    'image_id'      => '1000006970.tif',
                    'related_works' => array(),
                    'sort_order'    => 3,
                    'manifest_id'   => 'kmksa.be:524',
                    'height'        => 1337,
                    'width'         => 69
                ),
            'oai:datahub.vlaamsekunstcollectie.be:kmska.be:525' =>
                array(
                    'label'         => 'derp',
                    'attribution'   => 'MSK',
                    'related'       => 'arthub ID',
                    'description'   => 'a description',
                    'data_pid'      => 'oai:datahub.vlaamsekunstcollectie.be:kmska.be:525',
                    'image_id'      => '1000006971.tif',
                    'related_works' => array(),
                    'sort_order'    => 4,
                    'manifest_id'   => 'kmksa.be:525',
                    'height'        => 1337,
                    'width'         => 69
                ),
            'oai:datahub.vlaamsekunstcollectie.be:kmska.be:525bis' =>
                array(
                    'label'         => 'derp',
                    'attribution'   => 'MSK',
                    'related'       => 'arthub ID',
                    'description'   => 'a description',
                    'data_pid'      => 'oai:datahub.vlaamsekunstcollectie.be:kmska.be:525bis',
                    'image_id'      => '1000006972.tif',
                    'related_works' => array(),
                    'sort_order'    => 5,
                    'manifest_id'   => 'kmksa.be:525bis',
                    'height'        => 1337,
                    'width'         => 69
                )
        );
        foreach($resources as $resource) {
            $currentData = $this->getResourceInfo($resource['ref']);
            $newResourceSpaceData = array(
                'label'         => '',
                'attribution'   => '',
                'related'       => '',
                'description'   => '',
                'data_pid'      => 'oai:datahub.vlaamsekunstcollectie.be:kmska.be:523-525bis',
                'related_works' => array(),
                'sort_order'    => 1
            );

            $dataPid = null;
            // Test data
//            $dataPid = 'oai:datahub.vlaamsekunstcollectie.be:mskgent.be:1903-L';
            $dataPid = 'oai:datahub.vlaamsekunstcollectie.be:kmska.be:523-525bis';
//            $newResourceSpaceData['data_pid'] = $dataPid;
            foreach($currentData as $data) {
                if($data->name == 'pidafbeelding') {
//                    $newResourceSpaceData['data_pid'] = $data->value;// TODO uncomment
//                    $dataPid = $data->value;// TODO uncomment
                } else if($data->name == 'originalfilename') {
                    // TODO image ID with or without extension?
                    // With extension
                    $newResourceSpaceData['image_id'] = $data->value;
                    // Without extension
//                    $dotPos = strrpos($data->value, '.');
//                    $newResourceSpaceData['image_id'] = $dotPos > 0 ? substr($data->value, 0, $dotPos) : $data->value;
                }
            }

            $newResourceSpaceData['manifest_id'] = $this->getManifestId($dataPid);

            // Add related works if this dataPid is already present in the image data
            if(array_key_exists($dataPid, $imageData)) {
                $newResourceSpaceData['related_work_type'] = 'relatedto';
                $imageData[$dataPid]['related_works'][$dataPid] = $newResourceSpaceData;
            } else {
                $imageData[$dataPid] = $newResourceSpaceData;
            }
        }
        return $imageData;
    }

    // Generates manifest ID's based on institution + work ID
    private function getManifestId($dataPid)
    {
        $expl = explode(':', $dataPid);
        $manifestId = '';
        for($i = 2; $i < count($expl); $i++) {
            $toAdd = $expl[$i];
            // TODO seems like a very hackish solution to remove the '.be' at the end of the institution ID, this should probably be improved
            $dotBeIndex = strpos($expl[$i], '.be');
            if($dotBeIndex > 0) {
                $toAdd = substr($toAdd, 0, $dotBeIndex);
            }
            $manifestId .= (empty($manifestId) ? '' : ':') . $toAdd;
        }
        return $manifestId;
    }

    private function addDatahubData(& $imageData)
    {
        try {
            // Fetch the necessary data from the Datahub
            if (!$this->datahubEndpoint)
                $this->datahubEndpoint = Endpoint::build($this->datahubUrl);

            foreach($imageData as $dataPid => $value) {
                $this->addDatahubDataToImage($dataPid, $imageData);
            }
        }
        catch(Exception $e) {
            echo $e . PHP_EOL;
        }
    }

    private function addDatahubDataToImage($dataPid, & $imageData)
    {
        $record = $this->datahubEndpoint->getRecord($dataPid, $this->metadataPrefix);
        $data = $record->GetRecord->record->metadata->children($this->namespace, true);
        $domDoc = new DOMDocument;
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
                            if($domNode->attributes->item($i)->nodeName == 'xml:sortorder') {
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
                            $relation = 'related';
                        }
                        // Get the image ID for the related data pid
                        // TODO what to do when are multiple images linked to the same data pid?
                        $imageId = '';
                        $height = 0;
                        $width = 0;
                        if(array_key_exists($relatedDataPid, $imageData)) {
                            $imageId = $imageData[$relatedDataPid]['image_id'];
                            $height = $imageData[$relatedDataPid]['height'];
                            $width = $imageData[$relatedDataPid]['width'];
                        }
                        $arr = array(
                            'related_work_type' => $relation,
                            'data_pid'          => $relatedDataPid,
                            'image_id'          => $imageId,
                            'sort_order'        => $sortOrder,
                            'height'            => $height,
                            'width'             => $width
                        );
                        $imageData[$dataPid]['related_works'][$relatedDataPid] = $arr;
                    }
                }
            }
        }

        // All all (multilingual) metadata along with title and description
        $imageData[$dataPid]['metadata'] = array();
        foreach($this->datahubLanguages as $language) {
            foreach ($this->dataDefinition as $key => $dataDef) {
                if(!array_key_exists('label', $dataDef) && $key != 'short_description') {
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
                        if (!array_key_exists($dataDef['label'], $imageData[$dataPid]['metadata'])) {
                            $imageData[$dataPid]['metadata'][$dataDef['label']] = array();
                        }
                        $imageData[$dataPid]['metadata'][$dataDef['label']][$language] = $value;
                    }
                    // Add manifest-level metadata (label, attribution, description)
                    if($language == $this->datahubLanguage) {
                        if ($key == 'title') {
                            $imageData[$dataPid]['label'] = $value;
                        } else if($key == 'publisher') {
                            $imageData[$dataPid]['attribution'] = $value;
                        } else if($key == 'short_description') {
                            $imageData[$dataPid]['description'] = $value;
                        }
                    }
                }
            }
        }
    }

    private function getBasicMetadata($data)
    {
        return array(
            'data_pid'    => $data['data_pid'],
            'image_id'    => $data['image_id'],
            'sort_order'  => $data['sort_order'],
            'height'      => $data['height'],
            'width'       => $data['width'],
        );
    }

    private function addAllRelations(& $imageData)
    {
        $relations = array();

        // Initialize the array containing all directly related works
        foreach($imageData as $dataPid => $value) {
            $relations[$dataPid] = array();
            foreach($value['related_works'] as $relatedWork) {
                $relations[$dataPid][] = $relatedWork['data_pid'];
            }
        }

        // Loop through all data pid's and keep adding relations until all related works contain references to each other
        $relationsChanged = true;
        while($relationsChanged) {
            $relationsChanged = false;
            foreach($relations as $dataPid => $related) {
                foreach($relations as $otherPid => $otherRelation) {
                    if(in_array($dataPid, $otherRelation)) {
                        foreach ($related as $pid) {
                            if (!in_array($pid, $otherRelation)) {
                                $relations[$otherPid][] = $pid;
                                $relationsChanged = true;
                            }
                        }
                    }
                }
            }
        }

        foreach($relations as $dataPid => $related) {
            foreach($related as $pid) {
                if($dataPid != $pid) {
                    if (!array_key_exists($pid, $imageData[$dataPid]['related_works'])) {
                        $data = $this->getBasicMetadata($imageData[$pid]);
                        $data['related_work_type'] = 'related';
                        $imageData[$dataPid]['related_works'][] = $data;
                    }
                }
            }
        }
    }

    private function addArthubRelations(& $imageData)
    {
        foreach($imageData as $dataPid => $value) {
            $imageData[$dataPid]['attribution'] = 'https://arthub.vlaamsekunstcollectie.be/nl/catalog/' . $value['manifest_id'];
        }
    }

    private function addCantaloupeData(& $imageData)
    {
        foreach($imageData as $dataPid => $value) {
            try {
                $jsonData = file_get_contents($this->cantaloupeUrl . $value['image_id'] . '/info.json');
                $data = json_decode($jsonData);
                $imageData[$dataPid]['height'] = $data->height;
                $imageData[$dataPid]['width'] = $data->width;
            } catch(Exception $e) {
                echo $e->getMessage();
                // TODO proper error reporting
            }
        }
    }

    private function generateManifests($imageData)
    {
        $manifests = array();
        foreach($imageData as $dataPid => $value) {

            // Fill in (multilingual) manifest data
            $manifestMetadata = array();
            foreach($value['metadata'] as $key => $metadata) {
                $arr = array();
                foreach($metadata as $language => $data) {
                    $arr[] = array(
                        '@language' => $language,
                        '@value'    => $data
                    );
                }
                $manifestMetadata[] = array(
                    'label' => $key,
                    'value' => $arr
                );
            }

            $canvases = array();
            $canvasData = array($value['sort_order'] => $this->getBasicMetadata($value));
            foreach($value['related_works'] as $relatedWork) {
                $index = $relatedWork['sort_order'];
                // In the case of colliding indexes, increment by 1 as long as needed
                // This way, we can still (more or less) preserve sort order while ensuring there is no index collision
                while(array_key_exists($index, $canvasData)) {
                    $index++;
                }
                $canvasData[$index] = $relatedWork;
            }
            ksort($canvasData);
            $index = 0;
            foreach($canvasData as $canvas) {
                $index++;
                $canvasId = 'https://imagehub.vlaamsekunstcollectie.be/iiif/2/' . $value['manifest_id'] . '/canvas/' . $index;
                $service = array(
                    '@context' => 'http://iiif.io/api/image/2/context.json',
                    '@id'      => 'http://imagehub.vlaamsekunstcollectie.be/iiif/2/' . $canvas['image_id'],
                    'profile'  => 'http://iiif.io/api/image/2/level2.json'
                );
                $resource = array(
                    // TODO is it a correct assumption that the resource type is 'jpeg'?
                    '@id'     => 'https://imagehub.vlaamsekunstcollectie.be/iiif/2/' . $canvas['image_id'] . '/full/full/0/default.jpg',
                    '@type'   => 'dctypes:Image',
                    'format'  => 'image/jpeg',
                    'service' => $service,
                    'height'  => $canvas['height'],
                    'width'   => $canvas['width']
                );
                $image = array(
                    '@context'   => 'http://iiif.io/api/presentation/2/context.json',
                    '@type   '   => 'oa:Annotation',
                    // TODO is it a correct assumption that the motivation is 'painting'?
                    'motivation' => 'sc:painting',
                    'resource'   => $resource,
                    'on'         => $canvasId
                );
                $canvases[] = array(
                    '@id'    => $canvasId,
                    '@type'  => 'sc:Canvas',
                    'label'  => $canvas['image_id'],
                    'height' => $canvas['height'],
                    'width'  => $canvas['width'],
                    'images' => array($image)
                );
            }

            // Fill in sequence data
            $manifestSequence = array(
                '@type'    => 'sc:Sequence',
                '@context' => 'http://iiif.io/api/presentation/2/context.json',
                'canvases' => $canvases
            );

            // Generate the whole manifest
            $manifest = array(
                '@context'         => 'http://iiif.io/api/presentation/2/context.json',
                '@type'            => 'sc:Manifest',
                // TODO with or without preceding https://? example manifest and ETL5 documentation give 2 different results
                '@id'              => 'https://imagehub.vlaamsekunstcollectie.be/iiif/2/' . $value['manifest_id'] . '/manifest',
                'label'            => $value['label'],
                'attribution'      => $value['attribution'],
                'related'          => $value['related'],
                'description'      => $value['description'],
                'metadata'         => $manifestMetadata,
                'viewingDirection' => 'left-to-right',
                'viewingHint'      => 'individuals',
                'sequences'        => array($manifestSequence)
            );
//            var_dump(json_encode($manifest,JSON_PRETTY_PRINT));

            $manifests[$value['manifest_id']] = json_encode($manifest);
        }

        return $manifests;
    }

    private function storeManifests($manifests, $dm)
    {
        foreach($manifests as $manifestId => $manifest) {
            $manifestDocument = new Manifest();
            $manifestDocument->setManifestId($manifestId);
            $manifestDocument->setData($manifest);
            $dm->persist($manifestDocument);
            $dm->flush();
            $dm->clear();
        }
    }
}
