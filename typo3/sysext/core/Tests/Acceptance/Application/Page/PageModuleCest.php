<?php

declare(strict_types=1);

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

namespace TYPO3\CMS\Core\Tests\Acceptance\Application\Page;

use TYPO3\CMS\Core\Tests\Acceptance\Support\ApplicationTester;
use TYPO3\CMS\Core\Tests\Acceptance\Support\Helper\PageTree;

/**
 * This testcase is used to check if the expected information is found when
 * the page module was opened.
 */
final class PageModuleCest
{
    public function _before(ApplicationTester $I): void
    {
        $I->useExistingSession('admin');
    }

    public function checkThatPageModuleHasAHeadline(ApplicationTester $I): void
    {
        // Select the root page
        $I->switchToMainFrame();
        $I->click('Page');
        $I->waitForElement('#typo3-pagetree-tree .nodes .node');
        // click on PID=0
        $I->clickWithLeftButton('#identifier-0_0 text.node-name');
        $I->switchToContentFrame();
        $I->canSee('Please select a page in the page tree to edit page content.');
    }

    public function editPageTitle(ApplicationTester $I, PageTree $pageTree): void
    {
        $currentPageTitle = 'styleguide TCA demo';
        $newPageTitle = 'styleguide TCA demo page';

        $I->switchToMainFrame();
        $I->click('Page');
        $I->waitForElement('#typo3-pagetree-tree .nodes .node');
        $pageTree->openPath([$currentPageTitle]);
        $I->switchToContentFrame();

        // Rename the page
        $this->renamePage($I, $currentPageTitle, $newPageTitle);

        // Now recover the old page title
        $this->renamePage($I, $newPageTitle, $currentPageTitle);
    }

    private function renamePage(ApplicationTester $I, string $oldTitle, string $newTitle): void
    {
        $editLinkSelector = 'typo3-backend-editable-page-title button';
        $inputFieldSelector = 'typo3-backend-editable-page-title input[name="newPageTitle"]';

        $I->waitForText($oldTitle, 10, 'h1');

        $I->comment('Activate inline edit of page title');
        $I->waitForElement($editLinkSelector);
        $I->click($editLinkSelector);
        $I->waitForElement($inputFieldSelector);

        $I->comment('Set new value and save');
        $I->fillField($inputFieldSelector, $newTitle);
        $I->click('typo3-backend-editable-page-title button[type="submit"]');

        $I->comment('See the new page title');
        $I->waitForElementNotVisible($inputFieldSelector);
        $I->waitForText($newTitle, 5, 'h1');
    }
}
