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

namespace TYPO3\CMS\Backend\Tests\Unit\Form\FormDataProvider;

use TYPO3\CMS\Backend\Form\FormDataProvider\UserTsConfig;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class UserTsConfigTest extends UnitTestCase
{
    protected UserTsConfig $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new UserTsConfig();
    }

    /**
     * @test
     */
    public function addDataSetsUserTypoScriptInResult(): void
    {
        $expected = ['foo'];
        $backendUserAuthenticationMock = $this->createMock(BackendUserAuthentication::class);
        $backendUserAuthenticationMock->method('getTSConfig')->willReturn($expected);
        $GLOBALS['BE_USER'] = $backendUserAuthenticationMock;
        $result = $this->subject->addData([]);
        self::assertEquals($expected, $result['userTsConfig']);
    }
}
