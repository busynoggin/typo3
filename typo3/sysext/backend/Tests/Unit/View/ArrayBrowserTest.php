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

namespace TYPO3\CMS\Backend\Tests\Unit\View;

use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\ArrayBrowser;
use TYPO3\CMS\Core\FormProtection\DisabledFormProtection;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Routing\RequestContextFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class ArrayBrowserTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    ///////////////////////////////
    // Tests concerning depthKeys
    ///////////////////////////////
    /**
     * @test
     */
    public function depthKeysWithEmptyFirstParameterAddsNothing(): void
    {
        $formProtectionFactory = $this->createMock(FormProtectionFactory::class);
        $formProtectionFactory->method('createForType')->willReturn(new DisabledFormProtection());
        $requestContextFactory = new RequestContextFactory(new BackendEntryPointResolver());
        $uriBuilderMock = $this->getMockBuilder(UriBuilder::class)->setConstructorArgs([new Router($requestContextFactory), $formProtectionFactory, $requestContextFactory])->getMock();
        GeneralUtility::setSingletonInstance(UriBuilder::class, $uriBuilderMock);
        $subject = new ArrayBrowser();
        self::assertEquals([], $subject->depthKeys([], []));
    }

    /**
     * @test
     */
    public function depthKeysWithNumericKeyAddsOneNumberForKeyFromFirstArray(): void
    {
        $formProtectionFactory = $this->createMock(FormProtectionFactory::class);
        $formProtectionFactory->method('createForType')->willReturn(new DisabledFormProtection());
        $requestContextFactory = new RequestContextFactory(new BackendEntryPointResolver());
        $uriBuilderMock = $this->getMockBuilder(UriBuilder::class)->setConstructorArgs([new Router($requestContextFactory), $formProtectionFactory, $requestContextFactory])->getMock();
        GeneralUtility::setSingletonInstance(UriBuilder::class, $uriBuilderMock);
        $subject = new ArrayBrowser();
        self::assertEquals([0 => 1], $subject->depthKeys(['foo'], []));
    }
}
