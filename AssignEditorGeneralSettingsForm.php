<?php

/**
 * @file plugins/generic/assignEditorGeneral/AssignEditorGeneralSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AssignEditorGeneralSettingsForm
 *
 * @brief Which manager-role groups of the press are assigned to new submissions.
 */

namespace APP\plugins\generic\assignEditorGeneral;

use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class AssignEditorGeneralSettingsForm extends Form
{
    public function __construct(private AssignEditorGeneralPlugin $plugin, private Context $context)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * Load the groups that are assigned now: the chosen ones or, without a choice, the default ones.
     */
    public function initData()
    {
        $contextId = (int) $this->context->getId();
        $this->setData('userGroupIds', $this->plugin->generalEditorGroups($contextId)->map(fn ($userGroup) => (int) $userGroup->id)->values()->all());
        parent::initData();
    }

    /**
     * Read the submitted groups.
     */
    public function readInputData()
    {
        $this->readUserVars(['userGroupIds']);
        parent::readInputData();
    }

    /**
     * Render the form with the manager-role groups of the press.
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $groups = [];
        foreach ($this->plugin->managerGroups((int) $this->context->getId()) as $userGroup) {
            $groups[] = ['id' => (int) $userGroup->id, 'name' => (string) $userGroup->getLocalizedData('name')];
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'managerGroups' => $groups,
            'selectedGroupIds' => AssignEditorGeneralPlugin::chosenGroupIds($this->getData('userGroupIds')),
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * Save the chosen groups; only manager-role groups of this press are kept.
     */
    public function execute(...$functionArgs)
    {
        $contextId = (int) $this->context->getId();
        $valid = $this->plugin->managerGroups($contextId)->map(fn ($userGroup) => (int) $userGroup->id)->all();
        $chosen = array_values(array_intersect(AssignEditorGeneralPlugin::chosenGroupIds($this->getData('userGroupIds')), $valid));
        $this->plugin->updateSetting($contextId, AssignEditorGeneralPlugin::SETTING_USER_GROUP_IDS, $chosen, 'object');

        return parent::execute(...$functionArgs);
    }
}
