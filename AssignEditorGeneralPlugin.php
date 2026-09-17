<?php

/**
 * @file plugins/generic/assignEditorGeneral/AssignEditorGeneralPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AssignEditorGeneralPlugin
 *
 * @brief OMP 3.5: when a new submission is completed, assigns every active user
 *  of the "Press editor" group (manager role) to the submission, with the
 *  notification and the e-mail OMP sends for a native editor assignment.
 *
 *  No core file is changed: the native SubmissionSubmitted event is listened to
 *  at runtime, mirroring the native AssignEditors listener and SubEditorsDAO.
 */

namespace APP\plugins\generic\assignEditorGeneral;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PKP\context\Context;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\log\SubmissionEmailLogEventType;
use PKP\mail\Mailable;
use PKP\mail\mailables\EditorAssigned;
use PKP\notification\Notification;
use PKP\notification\NotificationSubscriptionSettingsDAO;
use PKP\observers\events\SubmissionSubmitted;
use PKP\plugins\GenericPlugin;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\user\Collector;
use PKP\userGroup\UserGroup;
use Throwable;

class AssignEditorGeneralPlugin extends GenericPlugin
{
    /**
     * nameLocaleKey of the default "Press editor" group (shown as "Editor geral"
     * in pt_BR). A stable identifier, whatever the group is renamed to.
     */
    public const EDITOR_GROUP_LOCALE_KEY = 'default.groups.name.editor';

    /**
     * Name of a group created by hand, recognised only while the press has not chosen
     * its groups in the settings (installations of 1.0.x relied on it).
     */
    public const EDITOR_GROUP_NAME_FALLBACK = 'Editor geral';

    /** Setting: ids of the manager-role groups whose users are assigned. */
    public const SETTING_USER_GROUP_IDS = 'userGroupIds';

    /** The listener is registered once per request. */
    private static bool $listenerRegistered = false;

    /** Messages the transport accepted, counted through Laravel's MessageSent. */
    private static int $sentMessages = 0;

    private static bool $countingSent = false;

    /**
     * Register the plugin and its event listener.
     *
     * The listener is always registered and the context check happens when the
     * event fires (pkp/pkp-lib#11793): when generic plugins load, the context may
     * not be resolved yet, and checking then would miss the assignment.
     *
     * @param string $category
     * @param string $path
     * @param null|int $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (Application::isUnderMaintenance() || !$success) {
            return $success;
        }

        if (!self::$listenerRegistered) {
            self::$listenerRegistered = true;
            Event::listen(SubmissionSubmitted::class, function (SubmissionSubmitted $event): void {
                $this->handleSubmissionSubmitted($event);
            });
        }

        return $success;
    }

    /**
     * Name shown in the plugins list.
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.assignEditorGeneral.displayName');
    }

    /**
     * Description shown in the plugins list.
     */
    public function getDescription(): string
    {
        return __('plugins.generic.assignEditorGeneral.description');
    }

    /**
     * Add the settings action to the plugin entry in the plugins list.
     */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$request->getContext() || !$this->getEnabled()) {
            return $actions;
        }

        $url = $request->getRouter()->url($request, null, null, 'manage', null, [
            'verb' => 'settings',
            'plugin' => $this->getName(),
            'category' => 'generic',
        ]);
        array_unshift($actions, new LinkAction('settings', new AjaxModal($url, $this->getDisplayName()), __('manager.plugins.settings')));

        return $actions;
    }

    /**
     * Show and save the settings form.
     */
    public function manage($args, $request): JSONMessage
    {
        // The settings belong to a press; there is nothing to configure site-wide.
        $context = $request->getContext();
        if ($request->getUserVar('verb') !== 'settings' || !$context) {
            return parent::manage($args, $request);
        }

        $form = new AssignEditorGeneralSettingsForm($this, $context);
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->execute();
        (new NotificationManager())->createTrivialNotification($request->getUser()->getId());

        return new JSONMessage(true);
    }

    /**
     * The manager-role groups of a press, each with its localized name.
     *
     * get(), not cursor(): only get() hydrates the group settings (nameLocaleKey,
     * name); the native getByRoleIds() uses cursor() and returns them null.
     */
    public function managerGroups(int $contextId): Collection
    {
        return UserGroup::withRoleIds([Role::ROLE_ID_MANAGER])
            ->withContextIds([$contextId])
            ->get();
    }

    /**
     * The groups whose active users are assigned: those the press chose in the
     * settings or, while it has chosen none, the default "Press editor" group.
     */
    public function generalEditorGroups(int $contextId): Collection
    {
        $chosen = self::chosenGroupIds($this->getSetting($contextId, self::SETTING_USER_GROUP_IDS));
        $groups = $this->managerGroups($contextId);

        return $chosen
            ? $groups->filter(fn (UserGroup $userGroup) => in_array((int) $userGroup->id, $chosen, true))
            : $groups->filter(fn (UserGroup $userGroup) => self::isGeneralEditorGroup($userGroup->nameLocaleKey ?? null, $userGroup->getLocalizedData('name', 'pt_BR')));
    }

    /**
     * The group ids stored in the setting, as positive integers.
     *
     * @return int[]
     */
    public static function chosenGroupIds($stored): array
    {
        return array_values(array_unique(array_filter(array_map('intval', is_array($stored) ? $stored : []), fn (int $id) => $id > 0)));
    }

    /**
     * Whether a manager-role group is the default general editors group.
     */
    public static function isGeneralEditorGroup(?string $nameLocaleKey, ?string $brazilianName): bool
    {
        return $nameLocaleKey === self::EDITOR_GROUP_LOCALE_KEY
            || ($brazilianName !== null && trim($brazilianName) === self::EDITOR_GROUP_NAME_FALLBACK);
    }

    /**
     * Handle the native "submission completed" event.
     */
    public function handleSubmissionSubmitted(SubmissionSubmitted $event): void
    {
        $submission = $event->submission;
        $context = $event->context;

        if (!$this->getEnabled($context->getId())) {
            return;
        }

        // 1) The general editors groups (manager role) of the press.
        $editorGroups = $this->generalEditorGroups((int) $context->getId());

        if ($editorGroups->isEmpty()) {
            error_log('[assignEditorGeneral] No general editors group in context ' . $context->getId() . '.');
            return;
        }

        $notificationManager = new NotificationManager();
        /** @var NotificationSubscriptionSettingsDAO $notificationSubscriptionSettingsDao */
        $notificationSubscriptionSettingsDao = DAORegistry::getDAO('NotificationSubscriptionSettingsDAO');
        $emailTemplate = Repo::emailTemplate()->getByKey($context->getId(), EditorAssigned::getEmailTemplateKey());

        $assignedAny = false;

        foreach ($editorGroups as $userGroup) {
            $userGroupId = $userGroup->id;

            // 2) Every ACTIVE editor of the group in the press.
            $editors = Repo::user()->getCollector()
                ->filterByContextIds([$context->getId()])
                ->filterByUserGroupIds([$userGroupId])
                ->filterByStatus(Collector::STATUS_ACTIVE)
                // Read once: what the collector returns is walked lazily, and
                // counting it first would leave the loop below with nothing.
                ->getMany()
                ->all();

            // A group with no workflow stage is invisible in the participants of
            // the submission: the assignment is made and nobody sees it. Saying so
            // is the difference between a press that fixes its group and one that
            // thinks the plugin does nothing.
            if (!DB::table('user_group_stage')->where('user_group_id', $userGroupId)->exists()) {
                error_log('[assignEditorGeneral] The general editors group ' . $userGroupId
                    . ' of context ' . $context->getId() . ' is in no workflow stage: whoever is assigned'
                    . ' through it does not show among the participants of the submission.');
            }

            if (!count($editors)) {
                // A group with nobody active in it assigns nobody: without this
                // line the press would be left wondering why nothing happened.
                // A membership with no starting date does not count as active,
                // which is the usual reason for an empty group here.
                error_log('[assignEditorGeneral] The general editors group ' . $userGroupId
                    . ' of context ' . $context->getId() . ' has no active member: nobody was assigned.');
                continue;
            }

            foreach ($editors as $editor) {
                // 3) Already assigned with this group: no second assignment or notification.
                $already = StageAssignment::withSubmissionIds([$submission->getId()])
                    ->withUserId($editor->getId())
                    ->withUserGroupId($userGroupId)
                    ->first();
                if ($already) {
                    continue;
                }

                // 4) The editorial assignment (recommendOnly = false: a full editor).
                Repo::stageAssignment()->build($submission->getId(), $userGroupId, $editor->getId(), false);
                $assignedAny = true;

                // 5) In-app notification, as in the native flow.
                $notificationManager->createNotification(
                    $editor->getId(),
                    Notification::NOTIFICATION_TYPE_SUBMISSION_SUBMITTED,
                    $context->getId(),
                    Application::ASSOC_TYPE_SUBMISSION,
                    $submission->getId()
                );

                // 6) The "Editor assigned" e-mail, unless the editor unsubscribed.
                if (!$emailTemplate) {
                    continue;
                }

                $unsubscribed = in_array(
                    Notification::NOTIFICATION_TYPE_SUBMISSION_SUBMITTED,
                    $notificationSubscriptionSettingsDao->getNotificationSubscriptionSettings(
                        NotificationSubscriptionSettingsDAO::BLOCKED_EMAIL_NOTIFICATION_KEY,
                        $editor->getId(),
                        $context->getId()
                    )
                );
                if ($unsubscribed) {
                    continue;
                }

                $mailable = new EditorAssigned($context, $submission);
                $mailable
                    ->from($context->getData('contactEmail'), $context->getData('contactName'))
                    ->subject($emailTemplate->getLocalizedData('subject') ?? '')
                    ->body($emailTemplate->getLocalizedData('body') ?? '')
                    ->recipients([$editor]);

                // The submission's e-mail log records only what really left.
                if (!self::send($mailable)) {
                    error_log('[assignEditorGeneral] The "editor assigned" e-mail to user ' . $editor->getId() . ' about submission ' . $submission->getId() . ' was not accepted by the mail transport.');
                    continue;
                }
                try {
                    Repo::emailLogEntry()->logMailable(SubmissionEmailLogEventType::EDITOR_ASSIGN, $mailable, $submission);
                } catch (Throwable $e) {
                    error_log('[assignEditorGeneral] E-mail sent but not added to the log of submission ' . $submission->getId() . ': ' . $e->getMessage());
                }
            }
        }

        // 7) Clear the "assign an editor" notification of the decision panel.
        if ($assignedAny) {
            $notificationManager->updateNotification(
                Application::get()->getRequest(),
                $notificationManager->getDecisionStageNotifications(),
                null,
                Application::ASSOC_TYPE_SUBMISSION,
                $submission->getId()
            );
        }
    }

    /**
     * Send a message and tell whether the transport accepted it.
     *
     * PKP's mailer catches transport exceptions and only writes them to the error
     * log, so Mail::send() returns normally when SMTP refuses the message.
     * Laravel fires MessageSent only for a message handed to the transport.
     */
    public static function send(Mailable $mailable): bool
    {
        if (!self::$countingSent) {
            Event::listen(MessageSent::class, fn () => self::$sentMessages++);
            self::$countingSent = true;
        }

        $before = self::$sentMessages;
        Mail::send($mailable);

        return self::$sentMessages > $before;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\assignEditorGeneral\AssignEditorGeneralPlugin', '\AssignEditorGeneralPlugin');
}
