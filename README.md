# Assign General Editors — OMP plugin

[![OMP](https://img.shields.io/badge/OMP-3.5-brightgreen)](https://pkp.sfu.ca/omp/)
[![Version](https://img.shields.io/badge/version-1.0.1.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OMP 3.5](https://github.com/OJSBR/assignEditorGeneral/releases/download/1.0.1.0/assignEditorGeneral-1.0.1.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Monograph Press (OMP)** that, whenever a new submission is
completed, **automatically assigns every active user in the "Editor geral" (Press editor)
group** to the submission stage — as full editors — sending the same in-app notification and
e-mail that OMP sends on a native editor assignment.

OMP only auto-assigns editors that are configured as sub-editors of the submission's
**Series/Category**. This plugin adds the missing piece: a press-wide rule so that *all*
general editors are put on *every* new submission, with no per-series configuration.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OMP version | Branch | Plugin release |
|-------------|--------|----------------|
| OMP 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.1.0 |

The plugin does not patch any core file: it hooks the native `SubmissionSubmitted` event at
runtime (registered inside `register()`, with the `getEnabled()` check deferred to the event
handler per PKP issue #11793), mirroring the native `AssignEditors` listener and
`SubEditorsDAO`.

## Installation

1. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
   into `plugins/generic/` so you get `plugins/generic/assignEditorGeneral/`. Do not rename the folder.
2. Enable **Assign General Editors automatically** under the *Generic* plugins list.

## How it works

On every completed submission the plugin:

1. Resolves the **"Editor geral"** group by the stable `nameLocaleKey`
   `default.groups.name.editor` (the default *Press editor* manager group), with the literal
   name `Editor geral` as a fallback for manually-created groups.
2. Collects **all active users** in that group for the press (disabled users are skipped).
3. Creates a stage assignment for each of them as a **full editor** (`recommendOnly = false`)
   and fires the standard **submission-received notification** and the **"Editor assigned"**
   e-mail (respecting each user's e-mail unsubscribe settings).
4. **De-duplicates** against existing assignments, so an editor already on the submission is
   never assigned or notified twice, and clears the "assign an editor" task on the decision
   panel.

The native OMP behavior is preserved: sub-editors mapped to the submission's Series/Category
are still assigned by core — this plugin only **adds** the general editors on top.

The submission's e-mail log records the "Editor assigned" message only when the mail transport
accepted it: OMP's mailer swallows SMTP failures, so the plugin counts Laravel's `MessageSent`
event and writes a failure (user and submission ids, no address) to the PHP error log instead.

## Tests

- **PHP suite** (`tests/`, 18 tests): the class against the installed PKP, which groups count as
  general editors, the e-mail log written only after the transport accepts a message, no e-mail
  address in the server log, and the 38 translations. Run either way from the OMP root:

  ```bash
  php plugins/generic/assignEditorGeneral/tests/run.php
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/assignEditorGeneral/tests"
  ```

- Verified on OMP 3.5.0.5 with a temporary general editor and an in-memory mail transport: a
  completed submission gets one assignment (full editor), one notification and one "Editor
  assigned" message, logged once; a group with no member assigns nobody.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**.

## Contributing

Issues and pull requests are welcome. Please target the branch matching the OMP version you
are working against. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Monograph Press (OMP)** que, sempre que uma nova submissão é
finalizada, **atribui automaticamente todos os usuários ativos do grupo "Editor geral"
(Press editor)** à etapa de submissão — como editores plenos — enviando a mesma notificação
e o mesmo e-mail que o OMP dispara em uma atribuição nativa de editor.

O OMP só atribui automaticamente editores configurados como subeditores da **Série/Categoria**
da submissão. Este plugin adiciona a peça que falta: uma regra para a editora inteira, de
modo que *todos* os editores gerais entrem em *toda* nova submissão, sem configuração por
série.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).** Veja a seção
> [Créditos e autoria](#créditos-e-autoria) abaixo.

### Compatibilidade e branches

| Versão do OMP | Branch | Release do plugin |
|---------------|--------|-------------------|
| OMP 3.5.x     | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.1.0 |

O plugin não altera nenhum arquivo do core: engancha o evento nativo `SubmissionSubmitted` em
tempo de execução (registrado no `register()`, com o `getEnabled()` verificado dentro do
handler conforme a issue #11793 da PKP), imitando o listener nativo `AssignEditors` e o
`SubEditorsDAO`.

### Instalação

1. Instale por **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a
   pasta em `plugins/generic/` de forma a obter `plugins/generic/assignEditorGeneral/`.
2. Habilite **Atribuir Editores Gerais automaticamente** na lista de plugins *Genéricos*.

### Como funciona

A cada submissão finalizada o plugin: (1) resolve o grupo **"Editor geral"** pela chave
estável `nameLocaleKey` `default.groups.name.editor` (grupo padrão *Press editor*), com o
nome literal `Editor geral` como fallback; (2) coleta **todos os usuários ativos** desse
grupo na editora (usuários desativados são ignorados); (3) cria a atribuição de cada um como
**editor pleno** (`recommendOnly = false`), disparando a notificação de submissão recebida e
o e-mail **"Editor designado"** (respeitando o descadastro de e-mails); e (4) **deduplica**
contra atribuições existentes, para não atribuir/notificar duas vezes, e limpa a tarefa
"designe um editor" no painel de decisão.

O comportamento nativo do OMP é preservado: subeditores vinculados à Série/Categoria da
submissão continuam sendo atribuídos pelo core — este plugin apenas **soma** os editores
gerais.

O histórico de e-mails da submissão só registra o "Editor designado" quando o transporte de
e-mail aceitou a mensagem (o mailer do OMP engole falha de SMTP); a falha vai para o log do PHP,
com ids e sem endereço.

### Testes

Suíte PHP em `tests/` (18 testes, pelo `tests/run.php` ou pelo PHPUnit do PKP). Verificado no OMP
3.5.0.5 com um editor geral temporário e transporte de e-mail em memória: submissão finalizada
recebe uma atribuição, uma notificação e um e-mail "Editor designado", registrado uma vez.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
