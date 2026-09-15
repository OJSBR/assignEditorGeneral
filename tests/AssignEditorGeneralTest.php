<?php

/**
 * @file plugins/generic/assignEditorGeneral/tests/AssignEditorGeneralTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AssignEditorGeneralTest
 *
 * @brief Which groups are assigned, the stored choice, what the e-mail log records and the site level.
 */

namespace APP\plugins\generic\assignEditorGeneral\tests;

use APP\plugins\generic\assignEditorGeneral\AssignEditorGeneralPlugin;
use APP\plugins\generic\assignEditorGeneral\AssignEditorGeneralSettingsForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(AssignEditorGeneralPlugin::class)]
#[CoversClass(AssignEditorGeneralSettingsForm::class)]
class AssignEditorGeneralTest extends PKPTestCase
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

    public function testTheStoredChoiceIsReadAsPositiveGroupIds(): void
    {
        $this->assertSame([3, 7], AssignEditorGeneralPlugin::chosenGroupIds(['3', 7, '7', 0, -2, 'x']));
        $this->assertSame([], AssignEditorGeneralPlugin::chosenGroupIds(null));
        $this->assertSame([], AssignEditorGeneralPlugin::chosenGroupIds('3,7'));
    }

    public function testTheChosenGroupsReplaceTheDefaultDetection(): void
    {
        // Localized group names ask the request for its context: a router without a press answers none.
        $request = \APP\core\Application::get()->getRequest();
        if (!$request->getRouter()) {
            $router = new \APP\core\PageRouter();
            $router->setApplication(\APP\core\Application::get());
            $request->setRouter($router);
        }
        $group = function (int $id, ?string $key, ?string $name) {
            $userGroup = new \PKP\userGroup\UserGroup();
            $userGroup->id = $id;
            $userGroup->nameLocaleKey = $key;
            $userGroup->name = ['pt_BR' => $name];
            return $userGroup;
        };
        $groups = collect([
            $group(2, 'default.groups.name.manager', 'Gerente da editora'),
            $group(3, 'default.groups.name.editor', 'Editor da editora'),
            $group(9, null, 'Coordenação'),
        ]);
        $plugin = new class ($groups) extends AssignEditorGeneralPlugin {
            public ?array $stored = null;

            public function __construct(private $groups)
            {
                parent::__construct();
            }

            public function managerGroups(int $contextId): \Illuminate\Support\Collection
            {
                return $this->groups;
            }

            public function getSetting($contextId, $name)
            {
                return $this->stored;
            }
        };

        $this->assertSame([3], $plugin->generalEditorGroups(1)->map(fn ($g) => $g->id)->values()->all(), 'Without a choice, the default Press editor group.');
        $plugin->stored = [9, 2];
        $this->assertSame([2, 9], $plugin->generalEditorGroups(1)->map(fn ($g) => $g->id)->values()->all());
    }

    public function testTheSiteLevelHasNoSettingsToOpen(): void
    {
        $request = new class () {
            public function getContext()
            {
                return null;
            }

            public function getUserVar($name)
            {
                return $name === 'verb' ? 'settings' : null;
            }

            public function getRouter()
            {
                throw new \RuntimeException('The site level must not build a settings URL.');
            }
        };
        $plugin = new class () extends AssignEditorGeneralPlugin {
            public function getEnabled($contextId = null)
            {
                return true;
            }
        };

        $this->assertSame([], array_filter($plugin->getActions($request, []), fn ($action) => $action->getId() === 'settings'));
        $this->expectExceptionMessage('Unhandled management action!');
        $plugin->manage([], $request);
    }
}
