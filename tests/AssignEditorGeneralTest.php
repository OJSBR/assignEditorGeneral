<?php

/**
 * @file plugins/generic/assignEditorGeneral/tests/AssignEditorGeneralTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AssignEditorGeneralTest
 *
 * @brief Which groups count as general editors, and what the e-mail log records.
 */

namespace APP\plugins\generic\assignEditorGeneral\tests;

use APP\plugins\generic\assignEditorGeneral\AssignEditorGeneralPlugin;

class AssignEditorGeneralTest extends TestCase
{
    public function testTheDefaultPressEditorGroupAndAHandMadeOneAreRecognized(): void
    {
        $this->assertTrue(AssignEditorGeneralPlugin::isGeneralEditorGroup('default.groups.name.editor', null));
        $this->assertTrue(AssignEditorGeneralPlugin::isGeneralEditorGroup(null, ' Editor geral '));
        $this->assertFalse(AssignEditorGeneralPlugin::isGeneralEditorGroup('default.groups.name.manager', 'Gerente da editora'), 'The press manager is not a general editor.');
        $this->assertFalse(AssignEditorGeneralPlugin::isGeneralEditorGroup('default.groups.name.productionEditor', null));
        $this->assertFalse(AssignEditorGeneralPlugin::isGeneralEditorGroup(null, null));
    }

    public function testOnlyAnAcceptedMessageIsLogged(): void
    {
        // Mail::send() returns normally when SMTP refuses the message.
        $source = (string) file_get_contents(dirname(__DIR__) . '/AssignEditorGeneralPlugin.php');
        $send = strpos($source, 'if (!self::send($mailable)) {');
        $log = strpos($source, 'Repo::emailLogEntry()->logMailable(');
        $this->assertTrue($send !== false && $log !== false && $send < $log);
        $this->assertStringContainsString('Event::listen(MessageSent::class', $source);
        $this->assertSame(1, substr_count($source, 'Mail::send($'), 'Every message goes through send().');
    }

    public function testNoEmailAddressReachesTheServerLog(): void
    {
        foreach (explode("\n", (string) file_get_contents(dirname(__DIR__) . '/AssignEditorGeneralPlugin.php')) as $number => $line) {
            if (str_contains($line, 'error_log(')) {
                $this->assertStringNotContainsString('getEmail', $line, 'Line ' . ($number + 1));
            }
        }
    }
}
