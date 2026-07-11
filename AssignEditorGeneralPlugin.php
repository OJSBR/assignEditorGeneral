<?php

/**
 * @file AssignEditorGeneralPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AssignEditorGeneralPlugin
 *
 * @brief Plugin OMP 3.5: ao finalizar uma nova submissao, atribui
 *  automaticamente todos os usuarios ativos do grupo "Editor geral" (grupo
 *  padrao "Press editor", papel Gerente) a etapa de submissao, com a
 *  notificacao e o e-mail padrao do OMP.
 *
 *  Nao altera o codigo-fonte do OMP: engancha o evento nativo SubmissionSubmitted
 *  em runtime, imitando o listener nativo AssignEditors / SubEditorsDAO.
 */

namespace APP\plugins\generic\assignEditorGeneral;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PKP\db\DAORegistry;
use PKP\log\SubmissionEmailLogEventType;
use PKP\mail\mailables\EditorAssigned;
use PKP\notification\Notification;
use PKP\notification\NotificationSubscriptionSettingsDAO;
use PKP\observers\events\SubmissionSubmitted;
use PKP\plugins\GenericPlugin;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\user\Collector;
use PKP\userGroup\UserGroup;

class AssignEditorGeneralPlugin extends GenericPlugin
{
    /**
     * nameLocaleKey do grupo padrao "Press editor" (exibido como "Editor geral"
     * em pt_BR). Identificador estavel, independente de renomeacao/traducao.
     */
    private const EDITOR_GROUP_LOCALE_KEY = 'default.groups.name.editor';

    /** Fallback: nome literal, caso o grupo tenha sido criado manualmente. */
    private const EDITOR_GROUP_NAME_FALLBACK = 'Editor geral';

    /** Evita registrar o listener mais de uma vez na mesma request. */
    private static bool $listenerRegistered = false;

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (Application::isUnderMaintenance() || !$success) {
            return $success;
        }

        // OMP 3.5 (PKP #11793): SEMPRE registrar o listener; o check de
        // getEnabled() vai dentro do callback. Na hora do generic-load o
        // contexto pode ainda nao estar resolvido, entao adiar o getEnabled()
        // para o disparo do evento evita perder a atribuicao.
        if (!self::$listenerRegistered) {
            self::$listenerRegistered = true;
            Event::listen(SubmissionSubmitted::class, function (SubmissionSubmitted $event): void {
                $this->handleSubmissionSubmitted($event);
            });
        }

        return $success;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.assignEditorGeneral.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.assignEditorGeneral.description');
    }

    /**
     * Trata o evento nativo de submissao finalizada.
     */
    private function handleSubmissionSubmitted(SubmissionSubmitted $event): void
    {
        $submission = $event->submission;
        $context = $event->context;

        if (!$this->getEnabled($context->getId())) {
            return;
        }

        // 1) Resolve o(s) grupo(s) "Editor geral" (papel Gerente) do contexto.
        //    Usa ->get() (nao ->cursor()): so o get() hidrata os settings do grupo
        //    (nameLocaleKey/name) via SettingsBuilder::getModels(). O getByRoleIds()
        //    nativo usa cursor() e retornaria nameLocaleKey/name = null.
        $editorGroups = UserGroup::withRoleIds([Role::ROLE_ID_MANAGER])
            ->withContextIds([$context->getId()])
            ->get()
            ->filter(function (UserGroup $userGroup) {
                return ($userGroup->nameLocaleKey ?? null) === self::EDITOR_GROUP_LOCALE_KEY
                    || $userGroup->getLocalizedData('name', 'pt_BR') === self::EDITOR_GROUP_NAME_FALLBACK;
            });

        if ($editorGroups->isEmpty()) {
            error_log('[assignEditorGeneral] Grupo "Editor geral" nao encontrado no contexto ' . $context->getId());
            return;
        }

        $notificationManager = new NotificationManager();
        /** @var NotificationSubscriptionSettingsDAO $notificationSubscriptionSettingsDao */
        $notificationSubscriptionSettingsDao = DAORegistry::getDAO('NotificationSubscriptionSettingsDAO');
        $emailTemplate = Repo::emailTemplate()->getByKey($context->getId(), EditorAssigned::getEmailTemplateKey());

        $assignedAny = false;

        foreach ($editorGroups as $userGroup) {
            $userGroupId = $userGroup->id;

            // 2) Todos os editores ATIVOS desse grupo no contexto.
            $editors = Repo::user()->getCollector()
                ->filterByContextIds([$context->getId()])
                ->filterByUserGroupIds([$userGroupId])
                ->filterByStatus(Collector::STATUS_ACTIVE)
                ->getMany();

            foreach ($editors as $editor) {
                // 3) Dedup: se ja atribuido nesse grupo, nao reatribui nem renotifica.
                $already = StageAssignment::withSubmissionIds([$submission->getId()])
                    ->withUserId($editor->getId())
                    ->withUserGroupId($userGroupId)
                    ->first();
                if ($already) {
                    continue;
                }

                // 4) Cria a atribuicao editorial (recommendOnly = false => editor pleno).
                //    build() e idempotente por si so (firstOr); a dedup acima e para as notificacoes.
                Repo::stageAssignment()->build(
                    $submission->getId(),
                    $userGroupId,
                    $editor->getId(),
                    false
                );
                $assignedAny = true;

                // 5) Notificacao in-app (igual ao fluxo nativo).
                $notificationManager->createNotification(
                    $editor->getId(),
                    Notification::NOTIFICATION_TYPE_SUBMISSION_SUBMITTED,
                    $context->getId(),
                    Application::ASSOC_TYPE_SUBMISSION,
                    $submission->getId()
                );

                // 6) E-mail "Editor designado", respeitando quem se descadastrou.
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

                Mail::send($mailable);
                Repo::emailLogEntry()->logMailable(
                    SubmissionEmailLogEventType::EDITOR_ASSIGN,
                    $mailable,
                    $submission
                );
            }
        }

        // 7) Limpa a notificacao de "designe um editor" no painel de decisao.
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
}
