<?php

/*
 *
 * This is an example CLI command for eZ Platform v3 to integrate with
 * the Google Docs API. This is not a demonstration of pure best practices,
 * but a prototype of how this integration can be built.
 *
 * Expects repository to have a content type with the following details:
 *  - identifier: google_docs_document
 *  - fields:
 *    - title
 *    - body
 *
 * More on the topic on the blog post here:
 *
 * https://ezplatform.com/blog/import-google-docs-ez-platform
 *
 *
*/

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use eZ\Publish\API\Repository\ContentService;
use eZ\Publish\API\Repository\ContentTypeService;
use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\PermissionResolver;
use eZ\Publish\API\Repository\UserService;
use Symfony\Component\HttpClient\HttpClient;

class GdocLoadCommand extends Command
{
    protected static $defaultName = 'app:gdoc-import';

    protected function configure()
    {
        $this
            ->setDescription('Import a document from Google Docs to eZ Platform')
        ;
    }

    protected $contentTypeService;
    protected $contentService;
    protected $locationService;
    protected $permissionResolver;
    protected $userService;
    protected $contentImportResolver;

    public function __construct(
        ContentTypeService $contentTypeService,
        ContentService $contentService,
        LocationService $locationService,
        PermissionResolver $permissionResolver,
        UserService $userService
    ){

        $this->contentTypeService = $contentTypeService;
        $this->contentService = $contentService;
        $this->locationService = $locationService;
        $this->permissionResolver = $permissionResolver;
        $this->userService = $userService;

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        // See how to setup Google docs API from the quickstart article:
        // https://developers.google.com/docs/api/quickstart/php
        // NOTE: Authentication based on valid credentials.json at root
        $client = $this->getClient();
        $service = new \Google_Service_Docs($client);
        $output->writeln('Google Docs client ok');

        // the Google Document ID
        $documentId = '1odeZoJXI6HN7aRs6SdzgWOWiJFHhi0mmygvuF8Mf5cw';
        $doc = $service->documents->get($documentId);
        $output->writeln('Loaded Document ' . $documentId);

        // Set user who can create articles (admin is 14 by default)
        $this->permissionResolver->setCurrentUserReference(
            $this->userService->loadUser(14)
        );

        // Create empty XML document for our use
        $richtextXml = $this->getRichtextXmlDocument();

        // Loop through the Google Docs elements and create matching XML fragments
        // See documentation:
        // - https://doc.ezplatform.com/en/latest/api/field_type_reference/#example-of-the-field-types-internal-format
        // - https://developers.google.com/docs/api/concepts/structure
        foreach($doc->getBody()->getContent() as $structuralElement){
            $simpleElement = $structuralElement->toSimpleObject();

            // Handle title
            if(
                isset($simpleElement->paragraph->paragraphStyle->namedStyleType)
                && strpos($simpleElement->paragraph->paragraphStyle->namedStyleType,'HEADING_') === 0
            ) {
                $titleElement = $this->getTitleElement($simpleElement->paragraph, $richtextXml);
                $richtextXml->firstChild->appendChild($titleElement);

            // Handle image
            } elseif (
                isset($simpleElement->paragraph->elements[0]->inlineObjectElement)
            ) {
                $imageElement = $this->getImageElement($simpleElement->paragraph, $richtextXml, $doc->getInlineObjects());
                $richtextXml->firstChild->appendChild($imageElement);

            // handle paragraph
            } elseif (isset($simpleElement->paragraph)) {
                $paragraphElement = $this->getParagraphElement($simpleElement->paragraph, $richtextXml);
                if($paragraphElement){
                    $richtextXml->firstChild->appendChild($paragraphElement);
                }

            // handle table
            } elseif (isset($simpleElement->table)) {
                $tableElement = $this->getTableElement($simpleElement->table, $richtextXml);
                $richtextXml->firstChild->appendChild($tableElement);

            // handle anything else
            } else {
                $output->writeln('Import for this element not implemented');
            }

        }

        // Try finding and updating based on remote ID
        try {
            $content = $this->contentService->loadContentByRemoteId('gdoc-' . $documentId);
            $this->updateContent($content, $doc, $richtextXml);
            $output->writeln('Content object updated');

        // Create new object if no existing found
        } catch (\eZ\Publish\Core\Base\Exceptions\NotFoundException $e){
            $this->createContent($doc, $richtextXml);
            $output->writeln('Content object created');
        }

        $output->writeln('Done.');

        return 1;

    }

    /**
     * Returns an authorized API client.
     * @return Google_Client the authorized client object
     */
    function getClient()
    {
        $client = new \Google_Client();
        $client->setApplicationName('Google Docs API PHP Quickstart');
        $client->setScopes(\Google_Service_Docs::DOCUMENTS_READONLY);
        $client->setAuthConfig('credentials.json');
        $client->setAccessType('offline');

        // Load previously authorized credentials from a file.
        $credentialsPath = $this->expandHomeDirectory('token.json');
        if (file_exists($credentialsPath)) {
            $accessToken = json_decode(file_get_contents($credentialsPath), true);
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

            // Store the credentials to disk.
            if (!file_exists(dirname($credentialsPath))) {
                mkdir(dirname($credentialsPath), 0700, true);
            }
            file_put_contents($credentialsPath, json_encode($accessToken));
            printf("Credentials saved to %s\n", $credentialsPath);
        }
        $client->setAccessToken($accessToken);

        // Refresh the token if it's expired.
        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($credentialsPath, json_encode($client->getAccessToken()));
        }
        return $client;
    }

    function expandHomeDirectory($path)
    {
        $homeDirectory = getenv('HOME');
        if (empty($homeDirectory)) {
            $homeDirectory = getenv('HOMEDRIVE') . getenv('HOMEPATH');
        }
        return str_replace('~', realpath($homeDirectory), $path);
    }

    function getRichtextXmlDocument(){

        $doc  = new \DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;
        $root = $doc->createElementNS('http://docbook.org/ns/docbook', 'section');
        $doc->appendChild($root);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:xlink', 'http://www.w3.org/1999/xlink');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:ezxhtml', 'http://ez.no/xmlns/ezpublish/docbook/xhtml');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:ezcustom', 'http://ez.no/xmlns/ezpublish/docbook/custom');
        $root->setAttribute('version','5.0-variant ezpublish-1.0');

        return $doc;

    }

    function getTitleElement($gdocElement, \DOMDocument $richtextXml){

        $gdocsStyleType = $gdocElement->paragraphStyle->namedStyleType;
        $headingLevel = explode('HEADING_',$gdocsStyleType);
        $headingLevel = array_pop($headingLevel);

        $content = trim($gdocElement->elements[0]->textRun->content);

        $titleElement = $richtextXml->createElement('title',$content);
        $titleElement->setAttribute('ezxhtml:level',$headingLevel);

        return $titleElement;

    }

    function getImageElement($gdocElement, \DOMDocument $richtextXml, $inlineObjects){

        $imageId = $gdocElement->elements[0]->inlineObjectElement->inlineObjectId;
        $imageUrl = $inlineObjects[$imageId]->getInlineObjectProperties()->getEmbeddedObject()->getImageProperties()->getContentUri();

        $imageObject = $this->getImageObject($imageId, $imageUrl);

        $embedElement = $richtextXml->createElement('ezembed');
        $embedElement->setAttribute('xlink:href','ezcontent://' . $imageObject->id);
        $embedElement->setAttribute('view','embed');
        $embedElement->setAttribute('ezxhtml:class','ez-embed-type-image');

        $configElement = $richtextXml->createElement('ezconfig');

        $sizeSettingElement = $richtextXml->createElement('ezvalue','large');
        $sizeSettingElement->setAttribute('key','size');

        $configElement->appendChild($sizeSettingElement);
        $embedElement->appendChild($configElement);

        return $embedElement;

    }

    function getParagraphElement($gdocElement, \DOMDocument $richtextXml){

        $paragraphElement = $richtextXml->createElement('para');

        foreach($gdocElement->elements as $element){

            $content = trim($element->textRun->content);

            if(isset($element->textRun->textStyle->link)){
                $linkElement = $richtextXml->createElement('link', ' ' . $content . ' ' );
                $linkElement->setAttribute('xlink:href',$element->textRun->textStyle->link->url);
                $paragraphElement->appendChild($linkElement);
            } else {
                if($content !== '') {
                    $textNode = $richtextXml->createTextNode($content);
                    $paragraphElement->appendChild($textNode);
                }
            }
        }

        if($paragraphElement->childNodes->length){
            return $paragraphElement;
        }

        return false;

    }

    function getTableElement($gdocElement, \DOMDocument $richtextXml){

        $tableElement = $richtextXml->createElement('informaltable');
        $tableElement->setAttribute('border','1');
        $tableElement->setAttribute('width','100%');

        $tableBodyElement = $richtextXml->createElement('tbody');

        foreach($gdocElement->tableRows as $tableRow){

            $rowElement = $richtextXml->createElement('tr');

            foreach($tableRow->tableCells as $tableCell){
                $cellContent = trim($tableCell->content[0]->paragraph->elements[0]->textRun->content);
                $cellPara = $richtextXml->createElement('para',$cellContent);

                $cellElement = $richtextXml->createElement('td');
                $cellElement->appendChild($cellPara);

                $rowElement->appendChild($cellElement);
            }

            $tableBodyElement->appendChild($rowElement);

        }

        $tableElement->appendChild($tableBodyElement);

        return $tableElement;

    }

    function updateContent($contentObject, $doc, $richtextXml){

        try {

            $contentInfo  = $this->contentService->loadContentInfo($contentObject->id);
            $contentDraft = $this->contentService->createContentDraft( $contentInfo );

            $contentUpdateStruct = $this->contentService->newContentUpdateStruct();
            $contentUpdateStruct->initialLanguageCode = 'eng-GB';

            $contentUpdateStruct->setField('title', $doc->getTitle());
            $contentUpdateStruct->setField('body',$richtextXml->saveXML());

            $draft = $this->contentService->updateContent($contentDraft->versionInfo, $contentUpdateStruct);
            $content = $this->contentService->publishVersion($draft->versionInfo);

        } catch (\Exception $e){
            dump($e);
        }

    }

    function createContent($doc, $richtextXml){

        try {

            $contentType = $this->contentTypeService->loadContentTypeByIdentifier('google_docs_document');

            $contentCreateStruct = $this->contentService->newContentCreateStruct($contentType,'eng-GB');
            $contentCreateStruct->remoteId = 'gdoc-' . $doc->documentId;

            $contentCreateStruct->setField('title', $doc->getTitle());
            $contentCreateStruct->setField('body',$richtextXml->saveXML());

            $locationCreateStruct = $this->locationService->newLocationCreateStruct(2);
            $draft = $this->contentService->createContent($contentCreateStruct,[$locationCreateStruct]);
            $content = $this->contentService->publishVersion($draft->versionInfo);

        } catch (\Exception $e){
            dump($e);
        }

    }

    function getImageObject($gdocImageId,$gdocImageUrl){

        $objectRemoteId = 'gdoc-image-' . $gdocImageId;

        try {

            $imageObject = $this->contentService->loadContentByRemoteId($objectRemoteId);
            return $imageObject;

        } catch (\eZ\Publish\Core\Base\Exceptions\NotFoundException $e){

            $client = HttpClient::create();
            $response = $client->request('GET',$gdocImageUrl);
            preg_match('|"(.*)"|', $response->getHeaders()['content-disposition'][0], $results);
            $originalFilename = array_pop($results);
            $tempFileName = sys_get_temp_dir() . $originalFilename;
            file_put_contents($tempFileName, $response->getContent());

            $contentType = $this->contentTypeService->loadContentTypeByIdentifier('image');

            $contentCreateStruct = $this->contentService->newContentCreateStruct($contentType,'eng-GB');
            $contentCreateStruct->remoteId = $objectRemoteId;

            $contentCreateStruct->setField('name', 'Google Docs image (' . $gdocImageId . ')');
            $contentCreateStruct->setField('image', $tempFileName);

            $locationCreateStruct = $this->locationService->newLocationCreateStruct(51);
            $draft = $this->contentService->createContent($contentCreateStruct,[$locationCreateStruct]);
            $content = $this->contentService->publishVersion($draft->versionInfo);

            return $content;

        } catch (\Exception $e){
            dump($e);
        }

    }

}