# Assign General Editors — OMP plugin

[![OMP](https://img.shields.io/badge/OMP-3.5-brightgreen)](https://pkp.sfu.ca/omp/)
[![Version](https://img.shields.io/badge/version-1.1.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OMP 3.5](https://github.com/OJSBR/assignEditorGeneral/releases/download/1.1.0.0/assignEditorGeneral-1.1.0.0.tar.gz) — or browse all [Releases](../../releases).

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
| OMP 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.1.0.0 |

The plugin does not patch any core file: it hooks the native `SubmissionSubmitted` event at
runtime (registered inside `register()`, with the `getEnabled()` check deferred to the event
handler per PKP issue #11793), mirroring the native `AssignEditors` listener and
`SubEditorsDAO`.

## Installation

1. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
   into `plugins/generic/` so you get `plugins/generic/assignEditorGeneral/`. Do not rename the folder.
2. Enable **Assign General Editors automatically** under the *Generic* plugins list.
3. Optionally, open the plugin **Settings** and check the manager-role groups whose users should be
   assigned. Until a choice is saved, the default *Press editor* group is used.

## How it works

On every completed submission the plugin:

1. Takes the manager-role groups checked in the plugin settings. Until a choice is saved, it
   resolves the default *Press editor* group by the stable `nameLocaleKey`
   `default.groups.name.editor`, with the name `Editor geral` recognised for a group created by
   hand in installations of 1.0.x.
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

- **PHPUnit** (`tests/*Test.php`, on `PKP\tests\PKPTestCase`): the classes against the installed
  PKP and PKP's plugin registry, the default Press editor group, the stored choice of groups
  replacing the default detection, the e-mail log written only after the transport accepts a
  message, no e-mail address in the server log, the site level without settings, the template and
  the 38 translations. From the OMP root:

  ```bash
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/assignEditorGeneral/tests"
  ```

- **Cypress** (`cypress/tests/functional/AssignEditorGeneral.cy.js`, run by
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) on OMP on every push): enables
  the plugin, lists the manager groups and saves a choice, putting the original one back.
- Verified on OMP 3.5.0.5 with an in-memory mail transport: a completed submission gets one
  assignment (full editor), one notification and one "Editor assigned" message per active user of
  the chosen group, logged once; the default group with no member assigns nobody.

Tests are kept in the repository and are not part of the release package.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring
it in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible
for the published releases.

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
| OMP 3.5.x     | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.1.0.0 |

O plugin não altera nenhum arquivo do core: engancha o evento nativo `SubmissionSubmitted` em
tempo de execução (registrado no `register()`, com o `getEnabled()` verificado dentro do
handler conforme a issue #11793 da PKP), imitando o listener nativo `AssignEditors` e o
`SubEditorsDAO`.

### Instalação

1. Instale por **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a
   pasta em `plugins/generic/` de forma a obter `plugins/generic/assignEditorGeneral/`.
2. Habilite **Atribuir Editores Gerais automaticamente** na lista de plugins *Genéricos*.
3. Se quiser, abra as **Configurações** do plugin e marque os grupos com papel de gestor cujos
   usuários devem ser designados. Enquanto nenhuma escolha for salva, vale o grupo padrão
   *Editor da editora*.

### Como funciona

A cada submissão finalizada o plugin: (1) usa os grupos com papel de gestor marcados nas
configurações ou, sem escolha salva, o grupo padrão *Editor da editora* pela chave estável
`nameLocaleKey` `default.groups.name.editor`, reconhecendo o nome `Editor geral` num grupo criado
à mão nas instalações da 1.0.x; (2) coleta **todos os usuários ativos** desse
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

PHPUnit em `tests/` (sobre `PKP\tests\PKPTestCase`) e Cypress em `cypress/tests/functional/`
(rodado pelo [pkp-github-actions](https://github.com/pkp/pkp-github-actions) no OMP a cada push),
com os comandos da seção em inglês. A suíte cobre o grupo padrão, a escolha de grupos gravada no
lugar da detecção padrão, o registro de e-mail só depois de o transporte aceitar a mensagem, nenhum
endereço no log do servidor, o nível do site sem configurações e as 38 traduções; o Cypress salva
uma escolha de grupos e devolve a original. Verificado no OMP 3.5.0.5 com transporte de e-mail em
memória: uma designação, uma notificação e um e-mail por usuário ativo do grupo escolhido.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
