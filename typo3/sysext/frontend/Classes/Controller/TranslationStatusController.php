<?php
namespace TYPO3\CMS\Frontend\Controller;

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

use TYPO3\CMS\Backend\Tree\View\PageTreeView;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Utility\IconUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class for displaying translation status of pages in the tree.
 */
class TranslationStatusController extends \TYPO3\CMS\Backend\Module\AbstractFunctionModule {

	/**
	 * Returns the menu array
	 *
	 * @return array
	 */
	public function modMenu() {
		$lang = $this->getLanguageService();
		$menuArray = array(
			'depth' => array(
				0 => $lang->getLL('depth_0'),
				1 => $lang->getLL('depth_1'),
				2 => $lang->getLL('depth_2'),
				3 => $lang->getLL('depth_3'),
				999 => $lang->sL('LLL:EXT:lang/locallang_core.xlf:labels.depth_infi')
			)
		);
		// Languages:
		$lang = $this->getSystemLanguages();
		$menuArray['lang'] = array(
			0 => '[All]'
		);
		foreach ($lang as $langRec) {
			$menuArray['lang'][$langRec['uid']] = $langRec['title'];
		}
		return $menuArray;
	}

	/**
	 * MAIN function for page information of localization
	 *
	 * @return string Output HTML for the module.
	 */
	public function main() {
		$theOutput = $this->pObj->doc->header($this->getLanguageService()->sL('LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_title'));
		if ($this->pObj->id) {
			// Depth selector:
			$theOutput .= '<div class="form-inline form-inline-spaced">';
			$h_func = BackendUtility::getDropdownMenu($this->pObj->id, 'SET[depth]', $this->pObj->MOD_SETTINGS['depth'], $this->pObj->MOD_MENU['depth']);
			$h_func .= BackendUtility::getDropdownMenu($this->pObj->id, 'SET[lang]', $this->pObj->MOD_SETTINGS['lang'], $this->pObj->MOD_MENU['lang']);
			$theOutput .= $h_func;
			// Add CSH:
			$theOutput .= BackendUtility::cshItem('_MOD_web_info', 'lang', NULL, '|<br />');
			$theOutput .= '</div>';
			// Showing the tree:
			// Initialize starting point of page tree:
			$treeStartingPoint = (int)$this->pObj->id;
			$treeStartingRecord = BackendUtility::getRecordWSOL('pages', $treeStartingPoint);
			$depth = $this->pObj->MOD_SETTINGS['depth'];
			// Initialize tree object:
			$tree = GeneralUtility::makeInstance(PageTreeView::class);
			$tree->init('AND ' . $this->getBackendUser()->getPagePermsClause(1));
			$tree->addField('l18n_cfg');
			// Creating top icon; the current page
			$HTML = IconUtility::getSpriteIconForRecord('pages', $treeStartingRecord);
			$tree->tree[] = array(
				'row' => $treeStartingRecord,
				'HTML' => $HTML
			);
			// Create the tree from starting point:
			if ($depth) {
				$tree->getTree($treeStartingPoint, $depth, '');
			}
			// Render information table:
			$theOutput .= $this->renderL10nTable($tree);
		}
		return $theOutput;
	}

	/**
	 * Rendering the localization information table.
	 *
	 * @param array $tree The Page tree data
	 * @return string HTML for the localization information table.
	 */
	public function renderL10nTable(&$tree) {
		$lang = $this->getLanguageService();
		// System languages retrieved:
		$languages = $this->getSystemLanguages();
		// Title length:
		$titleLen = $this->getBackendUser()->uc['titleLen'];
		// Put together the TREE:
		$output = '';
		$newOL_js = array();
		$langRecUids = array();
		foreach ($tree->tree as $data) {
			$tCells = array();
			$langRecUids[0][] = $data['row']['uid'];
			// Page icons / titles etc.
			$tCells[] = '<td' . ($data['row']['_CSSCLASS'] ? ' class="' . $data['row']['_CSSCLASS'] . '"' : '') . '>' .
				($data['depthData'] ?: '') .
				$GLOBALS['SOBE']->doc->wrapClickMenuOnIcon($data['HTML'], 'pages', $data['row']['uid']) .
				'<a href="#" onclick="' . htmlspecialchars(
					'top.loadEditId(' . (int)$data['row']['uid'] . ',"&SET[language]=0"); return false;'
				) . '" title="' . $lang->sL('LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_renderl10n_editPage') . '">' .
				htmlspecialchars(GeneralUtility::fixed_lgd_cs($data['row']['title'], $titleLen)) .
				'</a>' .
				((string)$data['row']['nav_title'] !== '' ? ' [Nav: <em>' . htmlspecialchars(GeneralUtility::fixed_lgd_cs($data['row']['nav_title'], $titleLen)) . '</em>]' : '') .
				'</td>';
			// DEFAULT language:
			// "View page" link is created:
			$viewPageLink = '<a href="#" onclick="' . htmlspecialchars(BackendUtility::viewOnClick(
					$data['row']['uid'], $GLOBALS['BACK_PATH'], '', '', '', '&L=###LANG_UID###')
				) . '" title="' . $lang->sL('LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_renderl10n_viewPage') . '">' .
				IconUtility::getSpriteIcon('actions-document-view') . '</a>';
			$status = $data['row']['l18n_cfg'] & 1 ? 'danger' : 'success';
			// Create links:
			$info = '';
			$editUid = $data['row']['uid'];
			$params = '&edit[pages][' . $editUid . ']=edit';
			$info .= '<a href="#" onclick="' . htmlspecialchars(BackendUtility::editOnClick($params))
				. '" title="' . $lang->sL(
					'LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_renderl10n_editDefaultLanguagePage'
				) . '">' . IconUtility::getSpriteIcon('actions-document-open') . '</a>';
			$info .= str_replace('###LANG_UID###', '0', $viewPageLink);
			$info .= '&nbsp;';
			$info .= $data['row']['l18n_cfg'] & 1 ? '<span title="' . $lang->sL('LLL:EXT:frontend/Resources/Private/Language/locallang_tca.xlf:pages.l18n_cfg.I.1', TRUE) . '">D</span>' : '&nbsp;';
			$info .= GeneralUtility::hideIfNotTranslated($data['row']['l18n_cfg']) ? '<span title="' . $lang->sL('LLL:EXT:frontend/Resources/Private/Language/locallang_tca.xlf:pages.l18n_cfg.I.2', TRUE) . '">N</span>' : '&nbsp;';
			// Put into cell:
			$tCells[] = '<td class="' . $status . ' col-border-left">' . $info . '</td>';
			$tCells[] = '<td class="' . $status . '" title="' . $lang->sL(
					'LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_renderl10n_CEcount'
				) . '" align="center">' . $this->getContentElementCount($data['row']['uid'], 0) . '</td>';
			$modSharedTSconfig = BackendUtility::getModTSconfig($data['row']['uid'], 'mod.SHARED');
			$disableLanguages = isset($modSharedTSconfig['properties']['disableLanguages']) ? GeneralUtility::trimExplode(',', $modSharedTSconfig['properties']['disableLanguages'], TRUE) : array();
			// Traverse system languages:
			foreach ($languages as $langRow) {
				if ($this->pObj->MOD_SETTINGS['lang'] == 0 || (int)$this->pObj->MOD_SETTINGS['lang'] === (int)$langRow['uid']) {
					$row = $this->getLangStatus($data['row']['uid'], $langRow['uid']);
					$info = '';
					if (is_array($row)) {
						$langRecUids[$langRow['uid']][] = $row['uid'];
						$status = $row['_HIDDEN'] ? (GeneralUtility::hideIfNotTranslated($data['row']['l18n_cfg']) || $data['row']['l18n_cfg'] & 1 ? 'danger' : '') : 'success';
						$icon = IconUtility::getSpriteIconForRecord(
							'pages_language_overlay',
							$row,
							array('class' => 'c-recIcon')
						);
						$info = $icon . htmlspecialchars(
								GeneralUtility::fixed_lgd_cs($row['title'], $titleLen)
							) . ((string)$row['nav_title'] !== '' ? ' [Nav: <em>' . htmlspecialchars(
								GeneralUtility::fixed_lgd_cs($row['nav_title'], $titleLen)
							) . '</em>]' : '') . ($row['_COUNT'] > 1 ? '<div>' . $lang->sL(
								'LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_renderl10n_badThingThereAre'
							) . '</div>' : '');
						$tCells[] = '<td class="' . $status . ' col-border-left">' .
							'<a href="#" onclick="' . htmlspecialchars(
								'top.loadEditId(' . (int)$data['row']['uid'] . ',"&SET[language]=' . $langRow['uid'] . '"); return false;'
							) . '" title="' . $lang->sL(
								'LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_renderl10n_editPageLang'
							) . '">' . $info . '</a></td>';
						// Edit whole record:
						$info = '';
						$editUid = $row['uid'];
						$params = '&edit[pages_language_overlay][' . $editUid . ']=edit';
						$info .= '<a href="#" onclick="' . htmlspecialchars(BackendUtility::editOnClick($params))
							. '" title="' . $lang->sL(
								'LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_renderl10n_editLanguageOverlayRecord'
							) . '">' . IconUtility::getSpriteIcon('actions-document-open') . '</a>';
						$info .= str_replace('###LANG_UID###', $langRow['uid'], $viewPageLink);
						$tCells[] = '<td class="' . $status . '">' . $info . '</td>';
						$tCells[] = '<td class="' . $status . '" title="' . $lang->sL(
								'LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_renderl10n_CEcount'
							) . '" align="center">' . $this->getContentElementCount($data['row']['uid'], $langRow['uid']) . '</td>';
					} else {
						if (in_array($langRow['uid'], $disableLanguages)) {
							// Language has been disabled for this page
							$status = 'danger';
							$info = '';
						} else {
							$status = GeneralUtility::hideIfNotTranslated($data['row']['l18n_cfg']) || $data['row']['l18n_cfg'] & 1 ? 'danger' : '';
							$info = '<input type="checkbox" name="newOL[' . $langRow['uid'] . '][' . $data['row']['uid'] . ']" value="1" />';
							$newOL_js[$langRow['uid']] .= '
								+(document.webinfoForm['
								. GeneralUtility::quoteJSvalue('newOL[' . $langRow['uid'] . '][' . $data['row']['uid'] . ']')
								. '].checked ? '
								. GeneralUtility::quoteJSvalue('&edit[pages_language_overlay][' . $data['row']['uid'] . ']=new')
								. ' : \'\')
							';
						}
						$tCells[] = '<td class="' . $status . ' col-border-left">&nbsp;</td>';
						$tCells[] = '<td class="' . $status . '">&nbsp;</td>';
						$tCells[] = '<td class="' . $status . '">' . $info . '</td>';
					}
				}
			}
			$output .= '
				<tr>
					' . implode('
					', $tCells) . '
				</tr>';
		}
		// Put together HEADER:
		$tCells = array();
		$tCells[] = '<td>' . $lang->sL('LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_renderl10n_page') . ':</td>';
		if (is_array($langRecUids[0])) {
			$params = '&edit[pages][' . implode(',', $langRecUids[0]) . ']=edit&columnsOnly=title,nav_title,l18n_cfg,hidden';
			$editIco = '<a href="#" onclick="' . htmlspecialchars(BackendUtility::editOnClick($params))
				. '" title="' . $lang->sL(
					'LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_renderl10n_editPageProperties'
				) . '">' . IconUtility::getSpriteIcon('actions-document-open') . '</a>';
		} else {
			$editIco = '';
		}
		$tCells[] = '<td class="col-border-left" colspan="2">' . $lang->sL(
				'LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_renderl10n_default'
			) . ':' . $editIco . '</td>';
		foreach ($languages as $langRow) {
			if ($this->pObj->MOD_SETTINGS['lang'] == 0 || (int)$this->pObj->MOD_SETTINGS['lang'] === (int)$langRow['uid']) {
				// Title:
				$tCells[] = '<td class="col-border-left">' . htmlspecialchars($langRow['title']) . '</td>';
				// Edit language overlay records:
				if (is_array($langRecUids[$langRow['uid']])) {
					$params = '&edit[pages_language_overlay][' .
						implode(',', $langRecUids[$langRow['uid']]) .
						']=edit&columnsOnly=title,nav_title,hidden';
					$tCells[] = '<td><a href="#" onclick="' . htmlspecialchars(BackendUtility::editOnClick($params))
						. '" title="' . $lang->sL(
							'LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_renderl10n_editLangOverlays'
						) . '">' . IconUtility::getSpriteIcon('actions-document-open') . '</a></td>';
				} else {
					$tCells[] = '<td>&nbsp;</td>';
				}
				// Create new overlay records:
				$params = '&columnsOnly=title,hidden,sys_language_uid&overrideVals[pages_language_overlay][sys_language_uid]=' . $langRow['uid'];
				$onClick = BackendUtility::editOnClick($params);
				if (!empty($newOL_js[$langRow['uid']])) {
					$onClickArray = explode('\'', $onClick);
					$lastElement = array_pop($onClickArray);
					array_push($onClickArray, $newOL_js[$langRow['uid']] . $lastElement);
					$onClick = implode('\'', $onClickArray);
				}
				$tCells[] = '<td><a href="#" onclick="' . htmlspecialchars($onClick)
					. '" title="' . $lang->sL(
						'LLL:EXT:frontend/Resources/Private/Language/locallang_webinfo.xlf:lang_getlangsta_createNewTranslationHeaders'
					) . '">' . IconUtility::getSpriteIcon('actions-document-new') . '</a></td>';
			}
		}

		$output =
			'<div class="table-fit">' .
				'<table class="table table-striped table-hover" id="langTable">' .
					'<thead>' .
						'<tr>' .
							implode('', $tCells) .
						'</tr>' .
					'</thead>' .
					'<tbody>' .
						$output .
					'</tbody>' .
				'</table>' .
			'</div>';
		return $output;
	}

	/**
	 * Selects all system languages (from sys_language)
	 *
	 * @return array System language records in an array.
	 */
	public function getSystemLanguages() {
		if (!$this->getBackendUser()->user['admin'] && $this->getBackendUser()->groupData['allowed_languages'] !== '') {
			$allowed_languages = array_flip(explode(',', $this->getBackendUser()->groupData['allowed_languages']));
		}
		$res = $this->getDatabaseConnection()->exec_SELECTquery('*', 'sys_language', '1=1' . BackendUtility::deleteClause('sys_language'));
		$outputArray = array();
		while ($row = $this->getDatabaseConnection()->sql_fetch_assoc($res)) {
			if (is_array($allowed_languages) && !empty($allowed_languages)) {
				if (isset($allowed_languages[$row['uid']])) {
					$outputArray[] = $row;
				}
			} else {
				$outputArray[] = $row;
			}
		}
		$this->getDatabaseConnection()->sql_free_result($res);
		return $outputArray;
	}

	/**
	 * Get an alternative language record for a specific page / language
	 *
	 * @param int $pageId Page ID to look up for.
	 * @param int $langId Language UID to select for.
	 * @return array pages_languages_overlay record
	 */
	public function getLangStatus($pageId, $langId) {
		$res = $this->getDatabaseConnection()->exec_SELECTquery(
			'*',
			'pages_language_overlay',
			'pid=' . (int)$pageId .
				' AND sys_language_uid=' . (int)$langId .
				BackendUtility::deleteClause('pages_language_overlay') .
				BackendUtility::versioningPlaceholderClause('pages_language_overlay')
		);
		$row = $this->getDatabaseConnection()->sql_fetch_assoc($res);
		BackendUtility::workspaceOL('pages_language_overlay', $row);
		if (is_array($row)) {
			$row['_COUNT'] = $this->getDatabaseConnection()->sql_num_rows($res);
			$row['_HIDDEN'] = $row['hidden'] || (int)$row['endtime'] > 0 && (int)$row['endtime'] < $GLOBALS['EXEC_TIME'] || $GLOBALS['EXEC_TIME'] < (int)$row['starttime'];
		}
		return $row;
	}

	/**
	 * Counting content elements for a single language on a page.
	 *
	 * @param int $pageId Page id to select for.
	 * @param int $sysLang Sys language uid
	 * @return int Number of content elements from the PID where the language is set to a certain value.
	 */
	public function getContentElementCount($pageId, $sysLang) {
		$count = $this->getDatabaseConnection()->exec_SELECTcountRows('uid', 'tt_content', 'pid=' . (int)$pageId . ' AND sys_language_uid=' . (int)$sysLang . BackendUtility::deleteClause('tt_content') . BackendUtility::versioningPlaceholderClause('tt_content'));
		return $count ?: '-';
	}

	/**
	 * Returns LanguageService
	 *
	 * @return \TYPO3\CMS\Lang\LanguageService
	 */
	protected function getLanguageService() {
		return $GLOBALS['LANG'];
	}

	/**
	 * Returns the database connection
	 *
	 * @return \TYPO3\CMS\Core\Database\DatabaseConnection
	 */
	protected function getDatabaseConnection() {
		return $GLOBALS['TYPO3_DB'];
	}

	/**
	 * Returns the current BE user.
	 *
	 * @return \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
	 */
	protected function getBackendUser() {
		return $GLOBALS['BE_USER'];
	}

}
