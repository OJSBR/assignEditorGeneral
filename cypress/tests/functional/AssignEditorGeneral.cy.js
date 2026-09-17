/**
 * @file cypress/tests/functional/AssignEditorGeneral.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the groups assigned to new submissions are chosen in the
 * plugin settings.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run). The defaults match the data set of PKP's continuous
 * integration, and the first test enables the plugin when it is off. The groups
 * checked at the start are saved back at the end, which assigns the same users.
 */

describe('Assign General Editors plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';

	const rowName = 'assigneditorgeneralplugin';
	const settingsForm = 'form[id="assignEditorGeneralSettings"]';
	const groups = settingsForm + ' input[name="userGroupIds[]"]';

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	// The Plugins tab can keep requests open for a while (the plugin gallery), hence the timeout.
	const waitJQuery = () => cy.window().its('jQuery.active', {timeout: 60000}).should('eq', 0);

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The website settings page on its Plugins tab (a new query string forces a load). Load it
	// once per test: loading it again while its plugin gallery request is pending stalls the
	// web server of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// Opens the settings modal from the grid, without reloading the page: a reload right
	// after saving can stall the web server of PKP's CI. The form is fetched each time.
	const openPluginSettings = (rowName, formSelector) => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		// The grid may still be animating the extras row: the link is clicked once it exists.
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(formSelector).data('pkp.handler')).to.exist;
		});
	};

	// ---- end of helpers ----

	const openSettings = () => openPluginSettings(rowName, settingsForm);

	const choose = (ids) => {
		cy.get(groups).each(($input) => {
			cy.wrap($input)[ids.includes($input.val()) ? 'check' : 'uncheck']({force: true});
		});
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		waitJQuery();
		cy.get(settingsForm).should('not.exist');
	};

	const checked = () => cy.get(settingsForm).then(($form) => $form.find('input[name="userGroupIds[]"]:checked').map((i, input) => input.value).get());

	it('Lists the manager groups and saves the chosen ones', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(rowName);
		openSettings();
		cy.get(groups).should('have.length.at.least', 1);
		cy.get(settingsForm).invoke('text').should('not.contain', '##');

		checked().then((original) => {
			cy.get(groups).last().invoke('val').then((last) => {
				choose([last]);
				openSettings();
				checked().should('deep.equal', [last]);

				// Put the original choice back.
				choose(original);
				openSettings();
				checked().should('deep.equal', original);
			});
		});
	});

	// A call of the REST API made from the page, carrying its session and token.
	const send = (path, method, body) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, {
			method: method,
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-Csrf-Token': win.pkp.currentUser.csrfToken},
			body: body === undefined ? undefined : JSON.stringify(body),
		}).then((response) => response.json().then((answer) => ({status: response.status, body: answer}))),
		{log: false, timeout: 60000}
	));


	// A press does not accept a submission without a file of the kind it asks for
	// (the manuscript). Which kinds exist is read from the page of the wizard
	// itself, so no id of any data set is written into the test, and a real file
	// is uploaded under each main kind — the extra ones do no harm.
	const uploadFile = (submission, locale, genreId) => cy.window({log: false}).then((win) => cy.wrap(
		(async () => {
			const form = new win.FormData();
			form.append('file', new win.File(['%PDF-1.4 OJSBR test file'], 'ojsbr-test.pdf', {type: 'application/pdf'}));
			form.append('fileStage', '2');
			form.append('genreId', String(genreId));
			form.append('name[' + locale + ']', 'ojsbr-test.pdf');
			const response = await win.fetch(pageUrl('api/v1/submissions/' + submission.id + '/files'), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {'X-Csrf-Token': win.pkp.currentUser.csrfToken},
				body: form,
			});

			return {status: response.status, body: await response.json()};
		})(),
		{log: false, timeout: 60000}
	));

	const attachRequiredFile = (submission, locale) => request(pageUrl('submission') + '?id=' + submission.id)
		.then((page) => {
			// The state of the page carries the kinds of file the press declares.
			const at = String(page.body).indexOf('"genres":');
			expect(at, 'the page of the wizard names the kinds of file').to.be.greaterThan(-1);
			const text = String(page.body).slice(at + '"genres":'.length);
			let depth = 0;
			let end = -1;
			for (let i = 0; i < text.length; i++) {
				if (text[i] === '[') {
					depth++;
				} else if (text[i] === ']') {
					depth--;
					if (depth === 0) {
						end = i + 1;
						break;
					}
				}
			}
			const genres = JSON.parse(text.slice(0, end).replace(/&quot;/g, '"'));
			const primary = genres.filter((genre) => genre.isPrimary).slice(0, 3);
			expect(primary, 'the press declares a main kind of file').to.not.be.empty;

			return cy.wrap(primary, {log: false});
		})
		.then((primary) => {
			primary.forEach((genre) => {
				uploadFile(submission, locale, genre.id).then((answer) => {
					expect(answer.status, 'the file was uploaded: ' + JSON.stringify(answer.body)).to.be.within(200, 201);
				});
			});
		});

	// Submissions made by the test, removed in after() even when an assertion fails.
	const madeHere = [];

	// The point of the plugin: whoever belongs to a general-editor group of the
	// press becomes a participant of a submission as soon as it is completed.
	// Nothing short of really completing one proves it.
	it('Makes the general editors participants of a submission that was just completed', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		openSettings();

		checked().then((original) => {
			cy.get(groups).last().invoke('val').then((groupId) => {
				// The press assigns this group, and these are its active members.
				choose([groupId]);
				cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
				api(pageUrl('api/v1/users?userGroupIds[]=' + groupId + '&status=active&count=50')).then((users) => {
					const expected = (users.items || []).map((item) => item.id);
					expect(expected, 'the chosen group has at least one editor').to.not.be.empty;

					cy.window({log: false}).its('pkp.context.primaryLocale').then((locale) => {
						send(pageUrl('api/v1/submissions'), 'POST', {locale: locale}).then((created) => {
							expect(created.status, 'the submission was created: ' + JSON.stringify(created.body)).to.be.within(200, 201);
							madeHere.push(created.body.id);
							const submission = created.body;

							return send(
								pageUrl('api/v1/submissions/' + submission.id + '/publications/' + submission.currentPublicationId),
								'PUT',
								{title: {[locale]: 'OJSBR assignEditorGeneral ' + Date.now()}}
							).then(() => attachRequiredFile(submission, locale))
								.then(() => send(pageUrl('api/v1/submissions/' + submission.id + '/submit'), 'PUT', {}))
								.then((submitted) => {
									expect(submitted.status, 'the submission was completed: ' + JSON.stringify(submitted.body)).to.eq(200);

									return api(pageUrl('api/v1/submissions/' + submission.id + '/participants'));
								});
						});
					}).then((participants) => {
						const list = Array.isArray(participants) ? participants : (participants.items || []);
						const ids = list.map((item) => item.id);
						expected.forEach((editorId) => {
							expect(ids, 'the editor ' + editorId + ' of the general group was not made a participant; '
								+ 'the submission has these participants: ' + JSON.stringify(participants).slice(0, 400))
								.to.include(editorId);
						});
					});
				});

				// The choice of the press goes back to what it was.
				choose(original);
			});
		});
	});

	after(function() {
		if (!madeHere.length) {
			return;
		}
		login(adminUser, adminPassword);
		madeHere.forEach((id) => send(pageUrl('api/v1/submissions/' + id), 'DELETE'));
	});

});
