<?php

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Backend\ElementBrowser;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\ElementBrowser\Event\IsFileSelectableEvent;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Tree\View\LinkParameterProviderInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Backend\View\FolderUtilityRenderer;
use TYPO3\CMS\Backend\View\RecordSearchBoxComponent;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\Exception;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Filter\FileExtensionFilter;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\FolderInterface;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\Search\FileSearchDemand;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Browser for files. This is used when adding a FAL inline image with the 'add image' button in FormEngine.
 *
 * @internal This class is a specific LinkBrowser implementation and is not part of the TYPO3's Core API.
 */
class FileBrowser extends AbstractElementBrowser implements ElementBrowserInterface, LinkParameterProviderInterface
{
    protected string $identifier = 'file';

    /**
     * When you click a folder name/expand icon to see the content of a certain file folder,
     * this value will contain the path of the expanded file folder.
     * If the value is NOT set, then it will be restored from the module session data.
     * Example value: "/www/htdocs/typo3/32/3dsplm/fileadmin/css/"
     *
     * @var string|null
     */
    protected $expandFolder;

    /**
     * @var FolderInterface|null
     */
    protected $selectedFolder;

    /**
     * @var string
     */
    protected $searchWord;

    /**
     * @var array
     */
    protected $thumbnailConfiguration = [];

    public function __construct(
        IconFactory $iconFactory,
        PageRenderer $pageRenderer,
        UriBuilder $uriBuilder,
        ExtensionConfiguration $extensionConfiguration,
        BackendViewFactory $backendViewFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct(
            $iconFactory,
            $pageRenderer,
            $uriBuilder,
            $extensionConfiguration,
            $backendViewFactory,
        );
    }

    /**
     * Loads additional JavaScript
     */
    protected function initialize()
    {
        parent::initialize();
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/browse-files.js');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/tree/file-storage-browser.js');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/multi-record-selection.js');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/global-event-handler.js');

        $thumbnailConfig = $this->getBackendUser()->getTSConfig()['options.']['file_list.']['thumbnail.'] ?? [];
        if (isset($thumbnailConfig['width']) && MathUtility::canBeInterpretedAsInteger($thumbnailConfig['width'])) {
            $this->thumbnailConfiguration['width'] = (int)$thumbnailConfig['width'];
        }
        if (isset($thumbnailConfig['height']) && MathUtility::canBeInterpretedAsInteger($thumbnailConfig['height'])) {
            $this->thumbnailConfiguration['height'] = (int)$thumbnailConfig['height'];
        }
    }

    /**
     * Checks additional GET/POST requests
     */
    protected function initVariables()
    {
        parent::initVariables();
        $this->expandFolder = $this->getRequest()->getParsedBody()['expandFolder'] ?? $this->getRequest()->getQueryParams()['expandFolder'] ?? null;
        $this->searchWord = $this->getRequest()->getParsedBody()['search_field'] ?? $this->getRequest()->getQueryParams()['search_field'] ?? '';
    }

    /**
     * Session data for this class can be set from outside with this method.
     *
     * @param mixed[] $data Session data array
     * @return array<int, array|bool> Session data and boolean which indicates that data needs to be stored in session because it's changed
     */
    public function processSessionData($data)
    {
        if ($this->expandFolder !== null) {
            $data['expandFolder'] = $this->expandFolder;
            $store = true;
        } else {
            $this->expandFolder = $data['expandFolder'] ?? null;
            $store = false;
        }
        return [$data, $store];
    }

    /**
     * @return string HTML content
     */
    public function render()
    {
        $request = $this->getRequest();
        $backendUser = $this->getBackendUser();

        // The key number 3 of the bparams contains the "allowed" string. Disallowed is not passed to
        // the element browser at all but only filtered out in DataHandler afterwards
        $allowedFileExtensions = GeneralUtility::trimExplode(',', explode('|', $this->bparams)[3], true);
        if (!empty($allowedFileExtensions) && $allowedFileExtensions[0] !== 'sys_file' && $allowedFileExtensions[0] !== '*') {
            // Create new filter object
            $filterObject = GeneralUtility::makeInstance(FileExtensionFilter::class);
            $filterObject->setAllowedFileExtensions($allowedFileExtensions);
            // Set file extension filters on all storages
            $storages = $backendUser->getFileStorages();
            foreach ($storages as $storage) {
                $storage->addFileAndFolderNameFilter([$filterObject, 'filterFileList']);
            }
        }
        if ($this->expandFolder) {
            $fileOrFolderObject = null;

            // Try to fetch the folder the user had open the last time he browsed files
            // Fallback to the default folder in case the last used folder is not existing
            try {
                $fileOrFolderObject = GeneralUtility::makeInstance(ResourceFactory::class)->retrieveFileOrFolderObject($this->expandFolder);
            } catch (Exception $accessException) {
                // We're just catching the exception here, nothing to be done if folder does not exist or is not accessible.
            } catch (\InvalidArgumentException $driverMissingException) {
                // We're just catching the exception here, nothing to be done if the driver does not exist anymore.
            }

            if ($fileOrFolderObject instanceof Folder) {
                // It's a folder
                $this->selectedFolder = $fileOrFolderObject;
            } elseif ($fileOrFolderObject instanceof FileInterface) {
                // It's a file
                $this->selectedFolder = $fileOrFolderObject->getParentFolder();
            }
        }
        // Or get the user's default upload folder
        if (!$this->selectedFolder instanceof Folder) {
            try {
                [, $pid, $table,, $field] = explode('-', explode('|', $this->bparams)[4]);
                if (($defaultUploadFolder = $backendUser->getDefaultUploadFolder((int)$pid, $table, $field)) instanceof FolderInterface) {
                    $this->selectedFolder = $defaultUploadFolder;
                }
            } catch (\Exception $e) {
                // The configured default user folder does not exist
            }
        }
        // Build the file upload and folder creation form
        $uploadForm = '';
        $createFolder = '';
        if ($this->selectedFolder instanceof Folder) {
            $folderUtilityRenderer = GeneralUtility::makeInstance(FolderUtilityRenderer::class, $this);
            $uploadForm = $folderUtilityRenderer->uploadForm($this->selectedFolder, $allowedFileExtensions);
            $createFolder = $folderUtilityRenderer->createFolder($this->selectedFolder);
        }

        // Getting flag for showing/not showing thumbnails:
        $displayThumbnailsSelector = true;
        if ($backendUser->getTSConfig()['options.']['noThumbsInEB'] ?? false) {
            $displayThumbnailsSelector = false;
            $displayThumbnails = false;
        } else {
            $displayThumbnails = $backendUser->getModuleData('file_list')['displayThumbs'] ?? true;
            $newValue = $request->getParsedBody()['displayThumbs'] ?? $request->getQueryParams()['displayThumbs'] ?? null;
            if ($newValue !== null) {
                $displayThumbnails = $newValue;
                $backendUser->pushModuleData('file_list', ['displayThumbs' => $displayThumbnails]);
                $backendUser->writeUC();
            }
        }
        if ($this->selectedFolder instanceof Folder) {
            $files = $this->renderFilesInFolder($this->selectedFolder, $allowedFileExtensions, (bool)$displayThumbnails, $displayThumbnailsSelector);
            $selectedFolderIcon = $this->iconFactory->getIconForResource($this->selectedFolder, Icon::SIZE_SMALL);
        } else {
            $files = '';
            $selectedFolderIcon = '';
        }
        $contentOnly = (bool)($request->getQueryParams()['contentOnly'] ?? false);

        $this->pageRenderer->setTitle($this->getLanguageService()->sL('LLL:EXT:backend/Resources/Private/Language/locallang_browse_links.xlf:fileSelector'));
        $view = $this->view;
        $view->assignMultiple([
            'selectedFolder' => $this->selectedFolder,
            'selectedFolderIcon' => $selectedFolderIcon,
            'treeEnabled' => true,
            'activeFolder' => $this->selectedFolder,
            'initialNavigationWidth' => $this->getBackendUser()->uc['selector']['navigation']['width'] ?? 250,
            'content' => $files . $uploadForm . $createFolder,
            'contentOnly' => $contentOnly,
        ]);
        $content = $this->view->render('ElementBrowser/Files');
        if ($contentOnly) {
            return $content;
        }
        $this->pageRenderer->setBodyContent('<body ' . $this->getBodyTagParameters() . '>' . $content);
        return $this->pageRenderer->render();
    }

    /**
     * For TYPO3 Element Browser: Expand folder of files.
     *
     * @param Folder $folder The folder path to expand
     * @param array $extensionList List of fileextensions to show
     * @param bool $displayThumbnails Whether to show thumbnails or not.
     * @param bool $displayThumbnailsSelector Whether to show thumbnails selector or not.
     * @return string HTML output
     */
    public function renderFilesInFolder(
        Folder $folder,
        array $extensionList = [],
        bool $displayThumbnails = true,
        bool $displayThumbnailsSelector = true,
    ) {
        if (!$folder->checkActionPermission('read')) {
            return '';
        }
        $lang = $this->getLanguageService();
        $titleLen = (int)$this->getBackendUser()->uc['titleLen'];

        if ($this->searchWord !== '') {
            $searchDemand = FileSearchDemand::createForSearchTerm($this->searchWord)->withRecursive();
            $files = $folder->searchFiles($searchDemand);
        } else {
            $extensionList = !empty($extensionList) && $extensionList[0] === '*' ? [] : $extensionList;
            $files = $this->getFilesInFolder($folder, $extensionList);
        }

        // Prepare search box, since the component should always be displayed, even if no files are available
        $searchBox = GeneralUtility::makeInstance(RecordSearchBoxComponent::class)
            ->setSearchWord($this->searchWord)
            ->render(
                $this->getRequest(),
                $this->getScriptUrl() . HttpUtility::buildQueryString($this->getUrlParameters([]), '&')
            );
        $searchBox = '<div class="mt-4 mb-4">' . $searchBox . '</div>';

        if (!count($files)) {
            // No files found. Either due to an active search or simply because the folder is empty.
            if ($this->searchWord !== '') {
                $message = sprintf(
                    $lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_browse_links.xlf:no_files_search'),
                    $folder->getStorage()->getName() . ':' . $folder->getReadablePath(),
                    $this->searchWord
                );
            } else {
                $message = sprintf(
                    $lang->sL('LLL:EXT:backend/Resources/Private/Language/locallang_browse_links.xlf:no_files'),
                    $folder->getStorage()->getName() . ':' . $folder->getReadablePath()
                );
            }

            GeneralUtility::makeInstance(FlashMessageService::class)
                ->getMessageQueueByIdentifier()
                ->addMessage(
                    GeneralUtility::makeInstance(FlashMessage::class, $message, '', ContextualFeedbackSeverity::INFO)
                );

            return $searchBox;
        }

        $lines = [];
        $tableHeader = '
            <thead>
                <tr>
                    <th colspan="3" class="nowrap">
                        <div class="btn-group dropdown position-static me-1">
                            <button type="button" class="btn btn-borderless dropdown-toggle t3js-multi-record-selection-check-actions-toggle" data-bs-toggle="dropdown" data-bs-boundary="window" aria-expanded="false">
                                ' . $this->iconFactory->getIcon('actions-selection', Icon::SIZE_SMALL) . '
                            </button>
                            <ul class="dropdown-menu t3js-multi-record-selection-check-actions">
                                <li>
                                    <button type="button" class="dropdown-item disabled" data-multi-record-selection-check-action="check-all" title="' . htmlspecialchars($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.checkAll')) . '">' .
                                        $this->iconFactory->getIcon('actions-selection-elements-all', Icon::SIZE_SMALL) . ' ' . htmlspecialchars($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.checkAll')) .
                                    '</button>
                                </li>
                                <li>
                                    <button type="button" class="dropdown-item disabled" data-multi-record-selection-check-action="check-none" title="' . htmlspecialchars($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.uncheckAll')) . '">' .
                                        $this->iconFactory->getIcon('actions-selection-elements-none', Icon::SIZE_SMALL) . ' ' . htmlspecialchars($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.uncheckAll')) .
                                    '</button>
                                </li>
                                <li>
                                    <button type="button" class="dropdown-item" data-multi-record-selection-check-action="toggle" title="' . htmlspecialchars($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.toggleSelection')) . '">' .
                                        $this->iconFactory->getIcon('actions-selection-elements-invert', Icon::SIZE_SMALL) . ' ' . htmlspecialchars($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.toggleSelection')) .
                                    '</button>
                                </li>
                            </ul>
                        </div>
                    </th>
                    <th class="col-control nowrap"></th>
                </tr>
            </thead>';

        foreach ($files as $fileObject) {
            // Thumbnail/size generation:
            $imgInfo = [];
            if ($displayThumbnails && ($fileObject->isMediaFile() || $fileObject->isImage())) {
                $processedFile = $fileObject->process(
                    ProcessedFile::CONTEXT_IMAGEPREVIEW,
                    $this->thumbnailConfiguration
                );
                $imageUrl = $processedFile->getPublicUrl();
                $imgInfo = [
                    $fileObject->getProperty('width'),
                    $fileObject->getProperty('height'),
                ];
                $pDim = $imgInfo[0] . 'x' . $imgInfo[1] . ' pixels';
                $clickIcon = '<img src="' . htmlspecialchars($imageUrl) . '"'
                    . ' width="' . $processedFile->getProperty('width') . '"'
                    . ' height="' . $processedFile->getProperty('height') . '" class="me-1" />';
            } else {
                $clickIcon = '';
                $pDim = '';
            }
            // Create file icon:
            $size = ' (' . GeneralUtility::formatSize($fileObject->getSize(), $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:byteSizeUnits')) . ($pDim ? ', ' . $pDim : '') . ')';
            $icon = '<span title="id=' . htmlspecialchars($fileObject->getUid()) . '">' . $this->iconFactory->getIconForResource($fileObject, Icon::SIZE_SMALL) . '</span>';
            if ($this->fileIsSelectableInFileList($fileObject, $imgInfo)) {
                $ATag = '<a href="#" class="btn btn-default" title="' . htmlspecialchars($fileObject->getName()) . '" data-file-name="' . htmlspecialchars($fileObject->getName()) . '" data-file-uid="' . $fileObject->getUid() . '" data-close="0">';
                $ATag .= '<span title="' . htmlspecialchars($lang->getLL('addToList')) . '">' . $this->iconFactory->getIcon('actions-plus', Icon::SIZE_SMALL)->render() . '</span>';
                $ATag_alt = '<a href="#" title="' . htmlspecialchars($fileObject->getName()) . $size . '" data-file-name="' . htmlspecialchars($fileObject->getName()) . '" data-file-uid="' . $fileObject->getUid() . '" data-close="1">';
                $ATag_e = '</a>';
                $bulkCheckBox = '
                    <span class="form-check form-toggle">
                        <input type="checkbox" data-file-name="' . htmlspecialchars($fileObject->getName()) . '" data-file-uid="' . $fileObject->getUid() . '" name="file_' . $fileObject->getUid() . '" value="0" autocomplete="off" class="form-check-input t3js-multi-record-selection-check"  />
                    </span>';
            } else {
                $ATag = '';
                $ATag_alt = '';
                $ATag_e = '';
                $bulkCheckBox = '';
            }
            // Create link to showing details about the file in a window:
            $Ahref = (string)$this->uriBuilder->buildUriFromRoute('show_item', [
                'type' => 'file',
                'table' => '_FILE',
                'uid' => $fileObject->getCombinedIdentifier(),
                'returnUrl' => $this->getRequest()->getAttribute('normalizedParams')->getRequestUri(),
            ]);

            // Combine the stuff:
            $filenameAndIcon = $ATag_alt . $icon . htmlspecialchars(GeneralUtility::fixed_lgd_cs($fileObject->getName(), $titleLen)) . $ATag_e;
            // Show element:
            $lines[] = '
                <tr data-multi-record-selection-element="true">
                    <td>' . $bulkCheckBox . '</td>
                    <td class="col-title nowrap">' . $filenameAndIcon . '</td>
                    <td class="nowrap">' . ($pDim ? $ATag_alt . $clickIcon . $ATag_e . $pDim : '') . '</td>
                    <td class="col-control">
                        <div class="btn-group">' . $ATag . $ATag_e . '
                        <a href="' . htmlspecialchars($Ahref) . '" class="btn btn-default" title="' . htmlspecialchars($lang->getLL('info')) . '">' . $this->iconFactory->getIcon('actions-document-info', Icon::SIZE_SMALL) . '</a>
                    </td>
                </tr>';
        }

        $markup = [];
        $markup[] = $searchBox;
        $markup[] = '<div id="filelist">';
        $markup[] = '  <div class="row row-cols-auto justify-content-between gx-0 list-header multi-record-selection-actions-wrapper">';
        $markup[] = '      <div class="col-auto">';
        $markup[] = '          <div class="row row-cols-auto align-items-center g-2 t3js-multi-record-selection-actions hidden">';
        $markup[] = '              <div class="col">';
        $markup[] = '                  <strong>' . htmlspecialchars($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.selection')) . '</strong>';
        $markup[] = '              </div>';
        $markup[] = '              <div class="col">';
        $markup[] = '                  <button type="button" class="btn btn-default btn-sm" data-multi-record-selection-action="import" title="' . htmlspecialchars($lang->getLL('importSelection')) . '">';
        $markup[] = '                      ' . $this->iconFactory->getIcon('actions-document-import-t3d', Icon::SIZE_SMALL) . ' ' . htmlspecialchars($lang->getLL('importSelection'));
        $markup[] = '                  </button>';
        $markup[] = '              </div>';
        $markup[] = '          </div>';
        $markup[] = '      </div>';
        $markup[] = '      ' . ($displayThumbnailsSelector ? $this->getThumbnailSelector($folder->getCombinedIdentifier(), $displayThumbnails) : '');
        $markup[] = '   </div>';
        $markup[] = '   <div class="table-fit">';
        $markup[] = '       <table class="table table-sm table-striped table-hover" id="typo3-filelist" data-list-container="files">';
        $markup[] = '           ' . $tableHeader;
        $markup[] = '           <tbody data-multi-record-selection-row-selection="true">';
        $markup[] = '               ' . implode('', $lines);
        $markup[] = '           </tbody>';
        $markup[] = '       </table>';
        $markup[] = '   </div>';
        $markup[] = '</div>';
        return implode('', $markup);
    }

    /**
     * Get a list of Files in a folder filtered by extension
     *
     * @return File[]
     */
    protected function getFilesInFolder(Folder $folder, array $extensionList)
    {
        if (!empty($extensionList)) {
            $filter = GeneralUtility::makeInstance(FileExtensionFilter::class);
            $filter->setAllowedFileExtensions($extensionList);
            $folder->setFileAndFolderNameFilters([[$filter, 'filterFileList']]);
        }
        return $folder->getFiles();
    }

    protected function getThumbnailSelector(string $identifier, bool $displayThumbnails): string
    {
        $parameters = $this->getUrlParameters(['identifier' => $identifier]);
        if (($route = $this->getRequest()->getAttribute('route')) instanceof Route) {
            $scriptUrl = (string)$this->uriBuilder->buildUriFromRoute($route->getOption('_identifier'), $parameters);
        } else {
            $scriptUrl = ($this->thisScript ?: PathUtility::basename(Environment::getCurrentScript())) . HttpUtility::buildQueryString($parameters, '&');
        }

        $attributes = [
            'type' => 'checkbox',
            'class' => 'form-check-input',
            'name' => 'displayThumbs',
            'value' => '1',
            'id' => 'checkDisplayThumbs',
            'data-global-event' => 'change',
            'data-action-navigate' => '$data=~s/$value/',
            'data-navigate-value' => sprintf('%s&%s=${value}', $scriptUrl, 'displayThumbs'),
            'data-empty-value' => '0',
        ];

        if ($displayThumbnails) {
            $attributes['checked'] = 'checked';
        }

        return '
            <div class="col-auto">
                <div class="form-check form-switch">
                    <input ' . GeneralUtility::implodeAttributes($attributes, true) . ' />
                    <label for="checkDisplayThumbs" class="form-check-label">
                        ' . htmlspecialchars($this->getLanguageService()->sL('LLL:EXT:backend/Resources/Private/Language/locallang_browse_links.xlf:displayThumbs')) . '
                    </label>
                </div>
            </div>';
    }

    /**
     * Checks if the given file is selectable in the filelist.
     *
     * By default, all files are selectable. This method may be overwritten in child classes.
     *
     * @param FileInterface $file
     * @param mixed[] $imgInfo Image dimensions from \TYPO3\CMS\Core\Imaging\GraphicalFunctions::getImageDimensions()
     * @return bool TRUE if file is selectable.
     */
    protected function fileIsSelectableInFileList(FileInterface $file, array $imgInfo)
    {
        return $this->eventDispatcher->dispatch(new IsFileSelectableEvent($file))->isFileSelectable();
    }

    /**
     * @param array $values Array of values to include into the parameters
     * @return string[] Array of parameters which have to be added to URLs
     */
    public function getUrlParameters(array $values)
    {
        return [
            'mode' => 'file',
            'expandFolder' => $values['identifier'] ?? $this->expandFolder,
            'bparams' => $this->bparams,
        ];
    }

    /**
     * @param array $values Values to be checked
     * @return bool Returns TRUE if the given values match the currently selected item
     */
    public function isCurrentlySelectedItem(array $values)
    {
        return false;
    }

    /**
     * Returns the URL of the current script
     *
     * @return string
     */
    public function getScriptUrl()
    {
        return $this->thisScript;
    }
}
