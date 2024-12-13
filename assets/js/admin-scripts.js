jQuery(document).ready(function ($) {
	function switchTab(hash) {
		const cleanHash = hash.replace('#', '');
		const navItem = $(`.at-nav-item[href="#${cleanHash}"]`);

		if (navItem.length === 0) return;

		// Get icon SVG content from nav item
		const iconSvg = navItem.find('.at-nav-item-icon').html();
		const title = navItem.find('.at-nav-item-label').text();

		// Update active state
		$('.at-nav-item').removeClass('active');
		navItem.addClass('active');

		// Update content visibility
		$('.at-tab-content').removeClass('active').hide();
		$(`#${cleanHash}`).addClass('active').show();

		// Update titlebar
		$('#current-section-icon').html(iconSvg);
		$('#current-section-title').text(title);

		// Update main content class
		const $mainContent = $('.at-main-content');
		$mainContent.removeClass(function (index, className) {
			return (className.match(/(^|\s)is-\S+/g) || []).join(' ');
		});
		$mainContent.addClass('is-' + cleanHash);
	}

	initTutorialVideos();

	$(window).on('hashchange', function () {
		const hash = window.location.hash || '#dashboard';
		switchTab(hash);
	});

	if (window.location.hash) {
		switchTab(window.location.hash);
	} else {
		switchTab('#dashboard');
	}

	$('.at-nav-item').on('click', function (e) {
		e.preventDefault();
		const hash = $(this).attr('href');
		window.location.hash = hash;
	});

	function initTutorialVideos() {
		const container = $('#tutorial-videos-container');
		const template = $('#video-item-template');
		const addButton = $('#add-video');
		const fallbackTemplate = `
			<div id="tutorial-videos-fallback" class="at-video-item-row at-empty-state">
				<div class="at-empty-state-icon">
					${$('#cloud-icon-template').html()}
				</div>
				<div class="at-empty-title">Klicke, um Dein erstes Video hinzuzufügen</div>
				<div class="at-empty-description">Du benötigst die Loom Video ID</div>
			</div>
		`;

		if (!container.length || !template.length) {
			return;
		}

		// Handle fallback click
		function initFallback() {
			const fallback = $('#tutorial-videos-fallback');
			if (fallback.length) {
				fallback.on('click', function () {
					const index = container.children().length;
					const newItem = template.html().replace(/{{INDEX}}/g, index);
					container.append(newItem);
					fallback.remove();
					addButton.show();
					initializeRemoveButtons();
				});
			}
		}

		// Add new video item
		addButton.on('click', function () {
			const index = container.children().length;
			const newItem = template.html().replace(/{{INDEX}}/g, index);
			container.append(newItem);
			initializeRemoveButtons();
		});

		function initializeRemoveButtons() {
			container
				.find('.at-remove-video')
				.off('click')
				.on('click', function () {
					$(this).closest('.at-video-item-row').remove();
					// Reindex remaining items
					container.children().each(function (index) {
						$(this)
							.find('input')
							.each(function () {
								const name = $(this).attr('name');
								$(this).attr('name', name.replace(/\[\d+\]/, '[' + index + ']'));
							});
					});

					// Show fallback if no videos are left
					if (container.children().length === 0) {
						container.after(fallbackTemplate);
						addButton.hide();
						initFallback();
					}
				});
		}

		initFallback();
		initializeRemoveButtons();
	}

	// Initial load of analytics data
	function initializeAnalyticsData() {
		const container = $('#analytics-data-container');
		const dateSelect = $('#analytics-date-range');

		// Check if container exists
		if (!container.length) return;

		// Try to load cached data first
		loadAnalyticsData(true).then((success) => {
			if (!success) {
				// If no cached data, load fresh data
				loadAnalyticsData(false);
			}
		});
	}

	function loadAnalyticsData(fromCacheOnly = false) {
		const range = $('#analytics-date-range').val();
		const container = $('#analytics-data-container');
		const button = $('#load-analytics-data');

		// Update UI state
		button.prop('disabled', true).html('<span class="loading">Lade Daten</span>');
		container.css('opacity', '0.6');

		return $.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: 'get_analytics_data',
				date_range: range,
				from_cache_only: fromCacheOnly,
				nonce: alfreds_toolbox.nonce,
			},
		})
			.then((response) => {
				if (response.success && response.data) {
					updateDashboardData(response.data);
					return true;
				}
				return false;
			})
			.catch(() => false)
			.always(() => {
				button.prop('disabled', false).html('Anwenden');
				container.css('opacity', '1');
			});
	}

	// Update dashboard with analytics data
	function updateDashboardData(data) {
		// Update Overview Cards
		Object.keys(data.overview).forEach(function (key) {
			const metric = data.overview[key];
			let value = metric.current;

			if (key === 'bounceRate' || key === 'engagementRate') {
				value = value.toFixed(2) + '%';
			} else if (key === 'avgDuration') {
				value = metric.current;
			} else {
				value = new Intl.NumberFormat('de-DE').format(value);
			}

			$(`.at-widget-card:contains("${metric.label}") .at-widget-description`).text(value);
		});

		// Update Lists
		updateListSection('Top Seiten', data.topPages);
		updateListSection('Geräte', data.devices);
		updateListSection('Browser', data.browsers);
		updateListSection('Länder', data.countries);
	}

	// Helper function to update list sections
	function updateListSection(title, items) {
		let listHtml = '';

		if (title === 'Browser') {
			Object.entries(items).forEach(([browserName, data]) => {
				const formattedNumber = new Intl.NumberFormat('de-DE').format(data.users);
				const formattedPercent = new Intl.NumberFormat('de-DE', {
					minimumFractionDigits: 1,
					maximumFractionDigits: 1,
					useGrouping: false,
				}).format(data.percent);

				// Sicherheitscheck für browserSvg
				const browserSvg = data.browserSvg || '';

				listHtml += `
					<div class="at-widget-list-item">
						<span class="at-widget-list-label">
							${browserSvg}
							${escapeHtml(data.name)}
						</span>
						<div class="at-widget-list-value">
							<span class="at-widget-list-number">${formattedNumber}</span>
							<span class="at-widget-list-percent">${formattedPercent}%</span>
						</div>
					</div>
				`;
			});
		} else if (title === 'Länder') {
			Object.entries(items).forEach(([countryCode, data]) => {
				const formattedNumber = new Intl.NumberFormat('de-DE').format(data.users);
				const formattedPercent = new Intl.NumberFormat('de-DE', {
					minimumFractionDigits: 1,
					maximumFractionDigits: 1,
					useGrouping: false,
				}).format(data.percent);

				// PHP escaped bereits das SVG für uns
				listHtml += `
                <div class="at-widget-list-item">
                    <span class="at-widget-list-label">
                        ${data.flagSvg}
                        ${escapeHtml(data.name)}
                    </span>
                    <div class="at-widget-list-value">
                        <span class="at-widget-list-number">${formattedNumber}</span>
                        <span class="at-widget-list-percent">${formattedPercent}%</span>
                    </div>
                </div>
            `;
			});
		} else if (title === 'Top Seiten') {
			items.forEach((item) => {
				listHtml += `
					<div class="at-widget-list-item">
						<span class="at-widget-list-label">${escapeHtml(item.path)}</span>
						<span class="at-widget-list-value">${new Intl.NumberFormat('de-DE').format(item.views)}</span>
					</div>
				`;
			});
		} else {
			Object.entries(items).forEach(([name, data]) => {
				const formattedNumber = new Intl.NumberFormat('de-DE').format(data.users);
				const formattedPercent = new Intl.NumberFormat('de-DE', {
					minimumFractionDigits: 1,
					maximumFractionDigits: 1,
					useGrouping: false,
				}).format(data.percent);

				let icon = '';
				if (title === 'Browser') {
					const browserIcon = getBrowserIcon(name.toLowerCase());
					icon = `<img src="${browserIcon}" class="at-widget-list-icon" alt="${name}">`;
				} else if (title === 'Länder') {
					const countryCode = data.countryCode || getCountryCode(name);
					const flagIcon = getFlagIcon(countryCode);
					icon = `<img src="${flagIcon}" class="at-widget-list-icon" alt="${name}">`;
				}

				listHtml += `
					<div class="at-widget-list-item">
						<span class="at-widget-list-label">
							${icon}
							${escapeHtml(name)}
						</span>
						<div class="at-widget-list-value">
							<span class="at-widget-list-number">${formattedNumber}</span>
							<span class="at-widget-list-percent">${formattedPercent}%</span>
						</div>
					</div>
				`;
			});
		}

		$(`.at-widget-card:contains("${title}") .at-widget-list`).html(listHtml);
	}

	// Helper Funktionen für Icons
	function getBrowserIcon(browser) {
		const iconPath = alfreds_toolbox.plugin_url + '/assets/icons/browsers/';
		const browserIcons = {
			chrome: 'chrome.svg',
			firefox: 'firefox.svg',
			safari: 'safari.svg',
			edge: 'edge.svg',
			opera: 'opera.svg',
			// ... weitere Browser
		};

		return iconPath + (browserIcons[browser] || 'browser-default.svg');
	}

	function getFlagIcon(countryCode) {
		const iconPath = alfreds_toolbox.plugin_url + '/assets/icons/flags/';
		return iconPath + (countryCode ? `${countryCode}.svg` : 'globe.svg');
	}

	function getCountryCode(countryName) {
		const countryMapping = {
			Deutschland: 'DE',
			Österreich: 'AT',
			Schweiz: 'CH',
			// ... weitere Länder
		};

		return countryMapping[countryName] || null;
	}

	// Helper function to escape HTML
	function escapeHtml(unsafe) {
		if (!unsafe) return ''; // Sicherheitscheck hinzugefügt
		return unsafe.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}

	// Initialize on page load
	initializeAnalyticsData();

	// Widget Toggle Card Status
	$('.at-switch input[type="checkbox"]').on('change', function () {
		$(this).closest('.at-widget-card').toggleClass('is-active', $(this).is(':checked'));
	});

	// Newsletter Signup Handler
	$('#newsletter-submit').on('click', function () {
		const $button = $(this);
		const $container = $('#at-newsletter-container');
		const email = $('#newsletter-email').val();
		const privacyAccepted = $('#newsletter-privacy').is(':checked');

		// Remove any existing messages
		$container.find('.at-message').remove();

		if (!email || !privacyAccepted) {
			$container.append('<div class="at-message at-message-error">Bitte füllen Sie alle Pflichtfelder aus.</div>');
			return;
		}

		// Disable form while submitting
		$button.prop('disabled', true).text('Wird eingetragen...');
		$container.find('input').prop('disabled', true);

		$.ajax({
			url: 'https://hook.eu1.make.com/09g2xlvx6iq4eur8sk3qi9gskt6s494u',
			type: 'POST',
			data: JSON.stringify({
				email: email,
				privacy_accepted: privacyAccepted,
				domain: $('#newsletter-domain').val(),
				language: $('#newsletter-language').val(),
			}),
			contentType: 'application/json',
			success: function () {
				$container.append('<div class="at-message at-message-success">Erfolgreich zum Newsletter angemeldet!</div>');

				setTimeout(() => {
					$container.find('.at-message').fadeOut(() => {
						$container.find('.at-message').remove();
					});
				}, 3000);

				$('#newsletter-email').val('');
				$('#newsletter-privacy').prop('checked', false);
			},
			error: function () {
				$container.append('<div class="at-message at-message-error">Es ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.</div>');
			},
			complete: function () {
				// Re-enable form
				$button.prop('disabled', false).text('Jetzt eintragen');
				$container.find('input').prop('disabled', false);
			},
		});
	});
	// Show checkbox when clicking into email field
	$('#newsletter-email').on('click', function () {
		$('.at-checkbox-group').addClass('is-visible');
	});

	// Cache Clear Funktionalität
	$('#clear_spotify_cache').click(function () {
		var button = $(this);
		button.prop('disabled', true);

		$.post(
			ajaxurl,
			{
				action: 'clear_spotify_cache',
				nonce: button.data('nonce'),
			},
			function (response) {
				if (response.success) {
					button.after('<span class="success" style="color:green;margin-left:10px">Cache wurde geleert</span>');
				} else {
					button.after('<span class="error" style="color:red;margin-left:10px">Fehler beim Leeren des Caches</span>');
				}
				button.prop('disabled', false);
				setTimeout(function () {
					button.siblings('.success, .error').fadeOut().remove();
				}, 3000);
			}
		);
	});

	// Event handler for manual refresh
	$('#load-analytics-data').on('click', function () {
		loadAnalyticsData(false);
	});

	// Automatically load data when the tab is shown
	$('.at-nav-item[href="#statistiken"]').on('click', function () {
		$('#load-analytics-data').click();
	});

	// Cache clear button
	$('#clear-analytics-cache').on('click', function () {
		var button = $(this);
		button.prop('disabled', true);

		$.ajax({
			url: ajaxurl,
			data: {
				action: 'clear_analytics_cache',
				nonce: alfreds_toolbox.nonce,
			},
			success: function (response) {
				if (response.success) {
					location.reload();
				} else {
					alert('Fehler beim Leeren des Cache');
				}
			},
			error: function () {
				alert('Fehler beim Leeren des Cache');
			},
			complete: function () {
				button.prop('disabled', false);
			},
		});
	});

	// FAQ Toggle
	$('.at-faq-item').on('click', function () {
		const $item = $(this);
		const $content = $item.find('.at-faq-description');

		if ($item.hasClass('is-active')) {
			$content.slideUp();
			$item.removeClass('is-active');
		} else {
			$('.at-faq-item.is-active .at-faq-description').slideUp();
			$('.at-faq-item.is-active').removeClass('is-active');

			$content.slideDown();
			$item.addClass('is-active');
		}
	});

	// Initial state: All closed
	$('.at-faq-item').removeClass('is-active').find('.at-faq-description').hide();

	// Settings Form Submit
	$('#at-settings-form').on('submit', function (e) {
		console.log('Form submitted');
		e.preventDefault();

		const $form = $(this);
		const $submitButton = $form.find('input[type="submit"]');
		const originalButtonText = $submitButton.val();

		const formData = new FormData();
		formData.append('action', 'save_alfreds_toolbox_settings');
		formData.append('nonce', alfreds_toolbox.nonce);

		// Widget-Aktivierungen
		const activeWidgets = [];
		$form.find('input[name="alfreds_toolbox_active_widgets[]"]').each(function () {
			if ($(this).is(':checked')) {
				activeWidgets.push($(this).val());
			}
		});
		formData.append('settings[alfreds_toolbox_active_widgets]', JSON.stringify(activeWidgets));

		// Tutorial Videos sammeln
		const tutorialVideos = [];
		$('#tutorial-videos-container .at-video-item-row').each(function (index) {
			const title = $(this).find('input[name^="tutorial_videos["][name$="[title]"]').val();
			const loomId = $(this).find('input[name^="tutorial_videos["][name$="[loom_id]"]').val();
			if (title || loomId) {
				// Nur hinzufügen wenn mindestens ein Feld gefüllt ist
				tutorialVideos.push({
					title: title,
					loom_id: loomId,
				});
			}
		});
		formData.append('settings[tutorial_videos]', JSON.stringify(tutorialVideos));

		console.log('Tutorial Videos being sent:', tutorialVideos);

		// Alle anderen Formularfelder
		$form.find('input:not([name="alfreds_toolbox_active_widgets[]"]), select, textarea').each(function () {
			const $field = $(this);
			const name = $field.attr('name');

			if (!name || $field.attr('type') === 'submit' || name.startsWith('tutorial_videos[')) {
				return;
			}

			if ($field.attr('type') === 'checkbox') {
				formData.append('settings[' + name + ']', $field.is(':checked') ? '1' : '0');
			} else if ($field.attr('type') === 'radio') {
				if ($field.is(':checked')) {
					formData.append('settings[' + name + ']', $field.val());
				}
			} else {
				formData.append('settings[' + name + ']', $field.val());
			}
		});

		console.log('Complete FormData:', Object.fromEntries(formData.entries()));

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			beforeSend: function () {
				$submitButton.val('Speichern...').prop('disabled', true);
			},
			success: function (response) {
				if (response.success) {
					const $message = $('<div class="at-message at-message-success">Einstellungen gespeichert</div>');
					$form.prepend($message);
					setTimeout(() => $message.fadeOut(() => $message.remove()), 3000);
				} else {
					const $message = $('<div class="at-message at-message-error">Fehler beim Speichern</div>');
					$form.prepend($message);
				}
			},
			error: function () {
				const $message = $('<div class="at-message at-message-error">Fehler beim Speichern</div>');
				$form.prepend($message);
			},
			complete: function () {
				$submitButton.val(originalButtonText).prop('disabled', false);
			},
		});
	});
});
