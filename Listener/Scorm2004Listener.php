<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\ScormBundle\Listener;

use Claroline\CoreBundle\Event\CopyResourceEvent;
use Claroline\CoreBundle\Event\CreateFormResourceEvent;
use Claroline\CoreBundle\Event\CreateResourceEvent;
use Claroline\CoreBundle\Event\OpenResourceEvent;
use Claroline\CoreBundle\Event\DeleteResourceEvent;
use Claroline\CoreBundle\Event\DownloadResourceEvent;
use Claroline\CoreBundle\Listener\NoHttpRequestException;
use Claroline\CoreBundle\Persistence\ObjectManager;
use Claroline\ScormBundle\Entity\Scorm2004Resource;
use Claroline\ScormBundle\Entity\Scorm2004Sco;
use Claroline\ScormBundle\Form\ScormType;
use Claroline\ScormBundle\Listener\Exception\InvalidScormArchiveException;
use JMS\DiExtraBundle\Annotation as DI;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * @DI\Service
 */
class Scorm2004Listener
{
    private $container;
    // path to the Scorm archive file
    private $filePath;
    private $formFactory;
    private $httpKernel;
    private $om;
    private $request;
    private $router;
    private $scormResourceRepo;
    // path to the Scorm unzipped files
    private $scormResourcesPath;
    private $scorm2004ScoTrackingRepo;
    private $templating;
    private $translator;

    /**
     * @DI\InjectParams({
     *     "container"          = @DI\Inject("service_container"),
     *     "formFactory"        = @DI\Inject("form.factory"),
     *     "httpKernel"         = @DI\Inject("http_kernel"),
     *     "om"                 = @DI\Inject("claroline.persistence.object_manager"),
     *     "requestStack"       = @DI\Inject("request_stack"),
     *     "router"             = @DI\Inject("router"),
     *     "templating"         = @DI\Inject("templating"),
     *     "translator"         = @DI\Inject("translator")
     * })
     */
    public function __construct(
        ContainerInterface $container,
        FormFactory $formFactory,
        HttpKernelInterface $httpKernel,
        ObjectManager $om,
        RequestStack $requestStack,
        UrlGeneratorInterface $router,
        TwigEngine $templating,
        TranslatorInterface $translator
    )
    {
        $this->container = $container;
        $this->filePath = $this->container
            ->getParameter('claroline.param.files_directory') . DIRECTORY_SEPARATOR;
        $this->formFactory = $formFactory;
        $this->httpKernel = $httpKernel;
        $this->om = $om;
        $this->request = $requestStack->getCurrentRequest();
        $this->router = $router;
        $this->scormResourceRepo = $om->getRepository('ClarolineScormBundle:Scorm2004Resource');
        $this->scormResourcesPath = $this->container
            ->getParameter('kernel.root_dir') . '/../web/uploads/scormresources/';
        $this->scorm2004ScoTrackingRepo = $om->getRepository('ClarolineScormBundle:Scorm2004ScoTracking');
        $this->templating = $templating;
        $this->translator = $translator;
    }

    /**
     * @DI\Observe("create_form_claroline_scorm_2004")
     *
     * @param CreateFormResourceEvent $event
     */
    public function onCreateForm(CreateFormResourceEvent $event)
    {
        $form = $this->formFactory->create(
            new ScormType(),
            new Scorm2004Resource()
        );
        $content = $this->templating->render(
            'ClarolineCoreBundle:Resource:createForm.html.twig',
            array(
                'form' => $form->createView(),
                'resourceType' => 'claroline_scorm_2004'
            )
        );
        $event->setResponseContent($content);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("create_claroline_scorm_2004")
     *
     * @param CreateResourceEvent $event
     */
    public function onCreate(CreateResourceEvent $event)
    {
        $form = $this->formFactory->create(
            new ScormType(),
            new Scorm2004Resource()
        );
        $form->handleRequest($this->request);

        try {
            if ($form->isValid()) {
                $tmpFile = $form->get('file')->getData();

                if ($this->isScormArchive($tmpFile)) {
                    $scormResource = $this->container
                        ->get('claroline.manager.scorm_manager')
                        ->createScorm($tmpFile, $form->get('name')->getData(), '2004');
                    $event->setResources(array($scormResource));
                    $event->stopPropagation();

                    return;
                }
            }
        } catch (InvalidScormArchiveException $e) {
            $msg = $e->getMessage();
            $errorMsg = $this->translator->trans(
                $msg,
                array(),
                'resource'
            );
            $form->addError(new FormError($errorMsg));
        }
        $content = $this->templating->render(
            'ClarolineCoreBundle:Resource:createForm.html.twig',
            array(
                'form' => $form->createView(),
                'resourceType' => $event->getResourceType()
            )
        );
        $event->setErrorFormContent($content);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("open_claroline_scorm_2004")
     *
     * @param OpenResourceEvent $event
     */
    public function onOpen(OpenResourceEvent $event)
    {
        if (!$this->request) {
            throw new NoHttpRequestException();
        }
        $scorm = $event->getResource();
        $params['_controller'] = 'ClarolineScormBundle:Scorm:renderScorm2004Resource';
        $params['scormId'] = $scorm->getId();

        $subRequest = $this->request->duplicate(
            array(),
            null,
            $params
        );
        $response = $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);

        $event->setResponse($response);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("delete_claroline_scorm_2004")
     *
     * @param DeleteResourceEvent $event
     */
    public function onDelete(DeleteResourceEvent $event)
    {
        $hashName = $event->getResource()->getHashName();
        $scormArchiveFile = $this->filePath . $hashName;
        $scormResourcesPath = $this->scormResourcesPath . $hashName;

        $nbScorm = (int)($this->scormResourceRepo->getNbScormWithHashName($hashName));

        if ($nbScorm === 1) {

            if (file_exists($scormArchiveFile)) {
                $event->setFiles(array($scormArchiveFile));
            }
            if (file_exists($scormResourcesPath)) {
                $this->deleteFiles($scormResourcesPath);
            }
        }
        $this->om->remove($event->getResource());
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("copy_claroline_scorm_2004")
     *
     * @param CopyResourceEvent $event
     */
    public function onCopy(CopyResourceEvent $event)
    {
        $resource = $event->getResource();
        $copy = new Scorm2004Resource();
        $copy->setHashName($resource->getHashName());
        $copy->setName($resource->getName());
        $this->om->persist($copy);

        $scos = $resource->getScos();

        foreach ($scos as $sco) {

            if (is_null($sco->getScoParent())) {
                $this->copySco($sco, $copy);
            }
        }

        $event->setCopy($copy);
        $event->stopPropagation();
    }

    /**
     * @DI\Observe("download_claroline_scorm_2004")
     *
     * @param DownloadResourceEvent $event
     */
    public function onDownload(DownloadResourceEvent $event)
    {
        $event->setItem($this->filePath . $event->getResource()->getHashName());
        $event->stopPropagation();
    }

    /**
     * Checks if a UploadedFile is a zip archive that contains a
     * imsmanifest.xml file in its root directory.
     *
     * @param UploadedFile $file
     *
     * @return boolean.
     */
    private function isScormArchive(UploadedFile $file)
    {
        $zip = new \ZipArchive();
        $openValue = $zip->open($file);

        $isScormArchive = ($openValue === true)
            && $zip->getStream("imsmanifest.xml");

        if (!$isScormArchive) {
            throw new InvalidScormArchiveException('invalid_scorm_archive_message');
        }

        return true;
    }

    /**
     * Deletes recursively a directory and its content.
     *
     * @param $dir The path to the directory to delete.
     */
    private function deleteFiles($dirPath)
    {
        foreach (glob($dirPath . '/*') as $content) {

            if (is_dir($content)) {
                $this->deleteFiles($content);
            } else {
                unlink($content);
            }
        }
        rmdir($dirPath);
    }

    /**
     * Associates SCORM resource to SCOs and persists them.
     * As array $scos can also contain an array of scos
     * this method is call recursively when an element is an array.
     *
     * @param Scorm2004Resource $scormResource
     * @param array $scos Array of Scorm2004Sco
     */
    private function persistScos(
        Scorm2004Resource $scormResource,
        array $scos
    )
    {
        foreach ($scos as $sco) {

            if (is_array($sco)) {
                $this->persistScos($scormResource, $sco);
            } else {
                $sco->setScormResource($scormResource);
                $this->om->persist($sco);
            }
        }
    }

    /**
     * Looks for the organization to use
     *
     * @param \DOMDocument $dom
     * @return array of Scorm2004Sco
     * @throws InvalidScormArchiveException If a default organization
     *         is defined and not found
     */
    private function parseOrganizationsNode(\DOMDocument $dom)
    {
        $organizationsList = $dom->getElementsByTagName('organizations');
        $resources = $dom->getElementsByTagName('resource');

        if ($organizationsList->length > 0) {
            $organizations = $organizationsList->item(0);
            $organization = $organizations->firstChild;

            if (!is_null($organizations->attributes)
                && !is_null($organizations->attributes->getNamedItem('default'))) {

                $defaultOrganization = $organizations->attributes->getNamedItem('default')->nodeValue;
            } else {
                $defaultOrganization = null;
            }
            // No default organization is defined
            if (is_null($defaultOrganization)) {

                while (!is_null($organization)
                    && $organization->nodeName !== 'organization') {

                    $organization = $organization->nextSibling;
                }

                if (is_null($organization)) {

                    return $this->parseResourceNodes($resources);
                }
            }
            // A default organization is defined
            // Look for it
            else {

                while (!is_null($organization)
                    && ($organization->nodeName !== 'organization'
                    || is_null($organization->attributes->getNamedItem('identifier'))
                    || $organization->attributes->getNamedItem('identifier')->nodeValue !== $defaultOrganization)) {

                    $organization = $organization->nextSibling;
                }

                if (is_null($organization)) {

                    throw new InvalidScormArchiveException('default_organization_not_found_message');
                }
            }

            return $this->parseItemNodes($organization, $resources);
        }
    }

    /**
     * Creates defined structure of SCOs
     *
     * @param \DOMNode $source
     * @param \DOMNodeList $resources
     * @return array of Scorm2004Sco
     * @throws InvalidScormArchiveException
     */
    private function parseItemNodes(
        \DOMNode $source,
        \DOMNodeList $resources,
        Scorm2004Sco $parentSco = null
    )
    {
        $item = $source->firstChild;
        $scos = array();

        while (!is_null($item)) {

            if ($item->nodeName === 'item') {
                $sco = new Scorm2004Sco();
                $scos[] = $sco;
                $sco->setScoParent($parentSco);
                $this->findAttrParams($sco, $item, $resources);
                $this->findNodeParams($sco, $item->firstChild);

                if ($sco->getIsBlock()) {
                    $scos[] = $this->parseItemNodes($item, $resources, $sco);
                }
            }
            $item = $item->nextSibling;
        }

        return $scos;
    }

    private function parseResourceNodes(\DOMNodeList $resources)
    {
        $scos = array();

        foreach ($resources as $resource) {

            if (!is_null($resource->attributes)) {
                $scormType = $resource->attributes->getNamedItemNS(
                    $resource->lookupNamespaceUri('adlcp'),
                    'scormType'
                );

                if (!is_null($scormType) && $scormType->nodeValue === 'sco') {
                    $identifier = $resource->attributes->getNamedItem('identifier');
                    $href = $resource->attributes->getNamedItem('href');

                    if (is_null($identifier)) {

                        throw new InvalidScormArchiveException('sco_with_no_identifier_message');
                    }
                    if (is_null($href)) {

                        throw new InvalidScormArchiveException('sco_resource_without_href_message');
                    }
                    $sco = new Scorm2004Sco();
                    $sco->setIsBlock(false);
                    $sco->setVisible(true);
                    $sco->setIdentifier($identifier->nodeValue);
                    $sco->setTitle($identifier->nodeValue);
                    $sco->setEntryUrl($href->nodeValue);
                    $scos[] = $sco;
                }
            }
        }

        return $scos;
    }

    /**
     * Initializes parameters of the SCO defined in attributes of the node.
     * It also look for the associated resource if it is a SCO and not a block.
     *
     * @param Scorm2004Sco $sco
     * @param \DOMNode $item
     * @param \DOMNodeList $resources
     * @throws InvalidScormArchiveException
     */
    private function findAttrParams(
        Scorm2004Sco $sco,
        \DOMNode $item,
        \DOMNodeList $resources
    )
    {
        $identifier = $item->attributes->getNamedItem('identifier');
        $isVisible = $item->attributes->getNamedItem('isvisible');
        $identifierRef = $item->attributes->getNamedItem('identifierref');
        $parameters = $item->attributes->getNamedItem('parameters');

        // throws an Exception if identifier is undefined
        if (is_null($identifier)) {
            throw new InvalidScormArchiveException('sco_with_no_identifier_message');
        }
        $sco->setIdentifier($identifier->nodeValue);

        // visible is true by default
        if (!is_null($isVisible) && $isVisible === 'false') {
            $sco->setVisible(false);
        } else {
            $sco->setVisible(true);
        }

        // set parameters for SCO entry resource
        if (!is_null($parameters)) {
            $sco->setParameters($parameters->nodeValue);
        }

        // check if item is a block or a SCO. A block doesn't define identifierref
        if (is_null($identifierRef)) {
            $sco->setIsBlock(true);
        } else {
            $sco->setIsBlock(false);
            // retrieve entry URL
            $sco->setEntryUrl(
                $this->findEntryUrl($identifierRef->nodeValue, $resources)
            );
        }
    }

    /**
     * Initializes parameters of the SCO defined in children nodes
     *
     * @param Scorm2004Sco $sco
     * @param \DOMNode $item
     */
    private function findNodeParams(Scorm2004Sco $sco, \DOMNode $item)
    {
        while (!is_null($item)) {

            switch ($item->nodeName) {
                case 'title':
                    $sco->setTitle($item->nodeValue);
                    break;
                case 'adlcp:timeLimitAction':
                    $action = strtolower($item->nodeValue);

                    if ($action === 'exit,message'
                        || $action === 'exit,no message'
                        || $action === 'continue,message'
                        || $action === 'continue,no message') {

                        $sco->setTimeLimitAction($action);
                    }
                    break;
                case 'adlcp:dataFromLMS':
                    $sco->setLaunchData($item->nodeValue);
                    break;
                case 'adlcp:completionThreshold':
                    $sco->setCompletionThreshold($item->nodeName);
                    break;
                case 'imsss:attemptAbsoluteDurationLimit':
                    $sco->setMaxTimeAllowed($item->nodeName);
                    break;
                case 'imsss:minNormalizedMeasure':
                    $sco->setScaledPassingScore($item->nodeName);
                    break;
            }
            $item = $item->nextSibling;
        }
    }

    /**
     * Searches for the resource with the given id and retrieve URL to its content.
     *
     * @param string $identifierref id of the resource associated to the SCO
     * @param \DOMNodeList $resources
     * @return string URL to the resource associated to the SCO
     * @throws InvalidScormArchiveException
     */
    public function findEntryUrl($identifierref, \DOMNodeList $resources)
    {
        foreach ($resources as $resource) {
            $identifier = $resource->attributes->getNamedItem('identifier');

            if (!is_null($identifier)) {
                $identifierValue = $identifier->nodeValue;

                if ($identifierValue === $identifierref) {
                    $href = $resource->attributes->getNamedItem('href');

                    if (is_null($href)) {

                        throw new InvalidScormArchiveException('sco_resource_without_href_message');
                    }

                    return $href->nodeValue;
                }
            }
        }
        throw new InvalidScormArchiveException('sco_without_resource_message');
    }

    /**
     * Copy given sco and its children
     *
     * @param Scorm2004Sco $sco
     * @param Scorm2004Resource $resource
     * @param Scorm2004Sco $scoParent
     */
    private function copySco(
        Scorm2004Sco $sco,
        Scorm2004Resource $resource,
        Scorm2004Sco $scoParent = null
    )
    {
        $scoCopy = new Scorm2004Sco();
        $scoCopy->setScormResource($resource);
        $scoCopy->setScoParent($scoParent);
        $scoCopy->setEntryUrl($sco->getEntryUrl());
        $scoCopy->setIdentifier($sco->getIdentifier());
        $scoCopy->setIsBlock($sco->getIsBlock());
        $scoCopy->setLaunchData($sco->getLaunchData());
        $scoCopy->setParameters($sco->getParameters());
        $scoCopy->setTimeLimitAction($sco->getTimeLimitAction());
        $scoCopy->setTitle($sco->getTitle());
        $scoCopy->setVisible($sco->isVisible());
        $this->om->persist($scoCopy);

        foreach ($sco->getScoChildren() as $scoChild) {
            $this->copySco($scoChild, $resource, $scoCopy);
        }
    }
}
