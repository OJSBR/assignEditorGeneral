{**
 * plugins/generic/assignEditorGeneral/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Settings: the manager-role groups assigned to new submissions.
 *}
<script>
	$(function() {ldelim}
		$('#assignEditorGeneralSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="assignEditorGeneralSettings" method="POST" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="assignEditorGeneralSettingsNotification"}

	{fbvFormArea id="assignEditorGeneralGroupsArea"}
		{fbvFormSection title="plugins.generic.assignEditorGeneral.settings.groups" description="plugins.generic.assignEditorGeneral.settings.groups.description" list=true}
			{foreach from=$managerGroups item=group}
				<li>
					<label>
						<input type="checkbox" name="userGroupIds[]" id="assignEditorGeneralGroup{$group.id|escape}" value="{$group.id|escape}"{if in_array($group.id, $selectedGroupIds)} checked="checked"{/if} />
						{$group.name|escape}
					</label>
				</li>
			{/foreach}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
