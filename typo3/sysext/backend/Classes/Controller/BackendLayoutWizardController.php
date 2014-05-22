<?php
namespace TYPO3\CMS\Backend\Controller;

/**
 * Script Class for grid wizard
 *
 * @author T3UXW09 Team1 <modernbe@cybercraft.de>
 */
class BackendLayoutWizardController {

	// GET vars:
	// Wizard parameters, coming from TCEforms linking to the wizard.
	/**
	 * @todo Define visibility
	 */
	public $P;

	/**
	 * Document template object
	 *
	 * @var \TYPO3\CMS\Backend\Template\SmallDocumentTemplate
	 * @todo Define visibility
	 */
	public $doc;

	// Accumulated content.
	/**
	 * @todo Define visibility
	 */
	public $content;

	/**
	 * Initialises the Class
	 *
	 * @return void
	 * @todo Define visibility
	 */
	public function init() {
		// Setting GET vars (used in frameset script):
		$this->P = \TYPO3\CMS\Core\Utility\GeneralUtility::_GP('P', 1);
		$this->formName = $this->P['formName'];
		$this->fieldName = $this->P['itemName'];
		$hmac_validate = \TYPO3\CMS\Core\Utility\GeneralUtility::hmac($this->formName . $this->fieldName, 'wizard_js');
		if (!$this->P['hmac'] || ($this->P['hmac'] !== $hmac_validate)) {
			throw new \InvalidArgumentException('Hmac Validation failed for backend_layout wizard', 1385811397);
		}
		$this->md5ID = $this->P['md5ID'];
		$uid = intval($this->P['uid']);
		// Initialize document object:
		$this->doc = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Backend\\Template\\StandardDocumentTemplate');
		$this->doc->backPath = $GLOBALS['BACK_PATH'];
		$pageRenderer = $this->doc->getPageRenderer();
		$pageRenderer->addJsFile($GLOBALS['BACK_PATH'] . TYPO3_MOD_PATH . 'res/grideditor.js');
		$pageRenderer->addJsInlineCode('storeData', '
			function storeData(data) {
				if (parent.opener && parent.opener.document && parent.opener.document.' . $this->formName . ' && parent.opener.document.' . $this->formName . '[' . \TYPO3\CMS\Core\Utility\GeneralUtility::quoteJSvalue($this->fieldName) . ']) {
					parent.opener.document.' . $this->formName . '[' . \TYPO3\CMS\Core\Utility\GeneralUtility::quoteJSvalue($this->fieldName) . '].value = data;
					parent.opener.TBE_EDITOR.fieldChanged("backend_layout","' . $uid . '","config","data[backend_layout][' . $uid . '][config]");
				}
			}
			', FALSE);
		$languageLabels = array(
			'save' => $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_wizards.xml:grid_labelSave', 1),
			'title' => $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_wizards.xml:grid_windowTitle', 1),
			'name' => $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_wizards.xml:grid_labelName', 1),
			'column' => $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_wizards.xml:grid_labelColumn', 1),
			'editCell' => $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_wizards.xml:grid_editCell', 1),
			'mergeCell' => $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_wizards.xml:grid_mergeCell', 1),
			'splitCell' => $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_wizards.xml:grid_splitCell', 1),
			'name' => $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_wizards.xml:grid_name', 1),
			'column' => $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_wizards.xml:grid_column', 1),
			'notSet' => $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_wizards.xml:grid_notSet', 1),
			'nameHelp' => $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_wizards.xml:grid_nameHelp', 1),
			'columnHelp' => $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_wizards.xml:grid_columnHelp', 1)
		);
		$pageRenderer->addInlineLanguageLabelArray($languageLabels);
		// Select record
		$record = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($this->P['field'], $this->P['table'], 'uid=' . intval($this->P['uid']));
		if (trim($record[0][$this->P['field']]) == '') {
			$rows = array(array(array('colspan' => 1, 'rowspan' => 1, 'spanned' => FALSE, 'name' => '')));
			$colCount = 1;
			$rowCount = 1;
		} else {
			// load TS parser
			$parser = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\TypoScript\\Parser\\TypoScriptParser');
			$parser->parse($record[0][$this->P['field']]);
			$data = $parser->setup['backend_layout.'];
			$rows = array();
			$colCount = $data['colCount'];
			$rowCount = $data['rowCount'];
			$dataRows = $data['rows.'];
			$spannedMatrix = array();
			for ($i = 1; $i <= $rowCount; $i++) {
				$cells = array();
				$row = array_shift($dataRows);
				$columns = $row['columns.'];
				for ($j = 1; $j <= $colCount; $j++) {
					$cellData = array();
					if (!$spannedMatrix[$i][$j]) {
						if (is_array($columns) && count($columns)) {
							$column = array_shift($columns);
							if (isset($column['colspan'])) {
								$cellData['colspan'] = (int)$column['colspan'];
								$columnColSpan = (int)$column['colspan'];
								if (isset($column['rowspan'])) {
									$columnRowSpan = intval($column['rowspan']);
									for ($spanRow = 0; $spanRow < $columnRowSpan; $spanRow++) {
										for ($spanColumn = 0; $spanColumn < $columnColSpan; $spanColumn++) {
											$spannedMatrix[$i + $spanRow][$j + $spanColumn] = 1;
										}
									}
								} else {
									for ($spanColumn = 0; $spanColumn < $columnColSpan; $spanColumn++) {
										$spannedMatrix[$i][$j + $spanColumn] = 1;
									}
								}
							} else {
								$cellData['colspan'] = 1;
								if (isset($column['rowspan'])) {
									$columnRowSpan = intval($column['rowspan']);
									for ($spanRow = 0; $spanRow < $columnRowSpan; $spanRow++) {
										$spannedMatrix[$i + $spanRow][$j] = 1;
									}
								}
							}
							if (isset($column['rowspan'])) {
								$cellData['rowspan'] = (int)$column['rowspan'];
							} else {
								$cellData['rowspan'] = 1;
							}
							if (isset($column['name'])) {
								$cellData['name'] = $column['name'];
							}
							if (isset($column['colPos'])) {
								$cellData['column'] = (int)$column['colPos'];
							}
						}
					} else {
						$cellData = array('colspan' => 1, 'rowspan' => 1, 'spanned' => 1);
					}
					$cells[] = $cellData;
				}
				$rows[] = $cells;
				if (!empty($spannedMatrix[$i]) && is_array($spannedMatrix[$i])) {
					ksort($spannedMatrix[$i]);
				}
			}
		}
		$pageRenderer->enableExtJSQuickTips();
		$pageRenderer->addExtOnReadyCode('
			t3Grid = new TYPO3.Backend.t3Grid({
				data: ' . json_encode($rows, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . ',
				colCount: ' . (int)$colCount . ',
				rowCount: ' . (int)$rowCount . ',
				targetElement: \'editor\'
			});
			t3Grid.drawTable();
			');
		$this->doc->styleSheetFile_post = TYPO3_MOD_PATH . 'res/grideditor.css';
	}

	/**
	 * Main Method, rendering either colorpicker or frameset depending on ->showPicker
	 *
	 * @return void
	 * @todo Define visibility
	 */
	public function main() {
		$content .= '<a href="#" title="' . $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_core.php:rm.saveDoc', TRUE) . '" onclick="storeData(t3Grid.export2LayoutRecord());return true;">' . \TYPO3\CMS\Backend\Utility\IconUtility::getSpriteIcon('actions-document-save') . '</a>';
		$content .= '<a href="#" title="' . $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_core.php:rm.saveCloseDoc', TRUE) . '" onclick="storeData(t3Grid.export2LayoutRecord());window.close();return true;">' . \TYPO3\CMS\Backend\Utility\IconUtility::getSpriteIcon('actions-document-save-close') . '</a>';
		$content .= '<a href="#" title="' . $GLOBALS['LANG']->sL('LLL:EXT:lang/locallang_core.php:rm.closeDoc', TRUE) . '" onclick="window.close();return true;">' . \TYPO3\CMS\Backend\Utility\IconUtility::getSpriteIcon('actions-document-close') . '</a>';
		$content .= $this->doc->spacer(10);
		$content .= '
		<table border="0" width="90%" height="90%" id="outer_container">
			<tr>
				<td class="editor_cell">
					<div id="editor">
					</div>
				</td>
				<td width="20" valign="center">
					<a class="addCol" href="#" title="' . $GLOBALS['LANG']->getLL('grid_addColumn') . '" onclick="t3Grid.addColumn(); t3Grid.drawTable(\'editor\');">
						<img src="res/t3grid-tableright.png" border="0" />
					</a><br />
					<a class="removeCol" href="#" title="' . $GLOBALS['LANG']->getLL('grid_removeColumn') . '" onclick="t3Grid.removeColumn(); t3Grid.drawTable(\'editor\');">
						<img src="res/t3grid-tableleft.png" border="0" />
					</a>
				</td>
			</tr>
			<tr>
				<td colspan="2" height="20" align="center">
					<a class="addCol" href="#" title="' . $GLOBALS['LANG']->getLL('grid_addRow') . '" onclick="t3Grid.addRow(); t3Grid.drawTable(\'editor\');">
						<img src="res/t3grid-tabledown.png" border="0" />
					</a>
					<a class="removeCol" href="#" title="' . $GLOBALS['LANG']->getLL('grid_removeRow') . '" onclick="t3Grid.removeRow(); t3Grid.drawTable(\'editor\');">
						<img src="res/t3grid-tableup.png" border="0" />
					</a>
				</td>
			</tr>
		</table>
		';
		$this->content = $content;
	}

	/**
	 * Returnes the sourcecode to the browser
	 *
	 * @return void
	 * @todo Define visibility
	 */
	public function printContent() {
		echo $this->doc->render('Grid wizard', $this->content);
	}

}


?>
