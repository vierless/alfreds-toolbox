jQuery(document).ready(function ($) {
	// Event Delegation für den Load More Button
	$(document).on('click', '.at_load-more-episodes', function (e) {
		e.preventDefault();

		const button = $(this);
		const showId = button.data('show-id');
		const offset = button.data('offset');
		const loadCount = button.data('load-count');
		const encodedSettings = button.data('settings');
		const settings = JSON.parse(atob(encodedSettings));

		if (button.hasClass('loading')) {
			return;
		}

		// Lade-Status hinzufügen
		button.addClass('loading');
		button.text('Lade weitere Episoden...');

		console.log('Sending AJAX request with:', {
			showId,
			offset,
			settings: atob(encodedSettings),
		});

		// AJAX Request
		$.ajax({
			url: spotify_widget_vars.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'load_more_episodes',
				show_id: showId,
				offset: offset,
				count: button.data('load-count'),
				nonce: spotify_widget_vars.nonce,
			},
			beforeSend: function () {
				button.addClass('loading');
				button.text(settings.load_more_loading_text);
			},
			success: function (response) {
				if (response.success && response.data.episodes) {
					const episodes = response.data.episodes;
					const settings = JSON.parse(atob(encodedSettings));

					episodes.forEach(function (episode) {
						const episodeHtml = renderEpisode(episode, settings);
						$('.at_spotify-podcast-grid').append(episodeHtml);
					});

					button.data('offset', offset + episodes.length);

					if (episodes.length === 0) {
						button.remove();
					}
				} else {
					console.error('Error in response:', response);
					button.text('Keine weiteren Episoden verfügbar');
					setTimeout(() => button.remove(), 2000);
				}
			},
			error: function (xhr, status, error) {
				console.error('AJAX Error:', {
					status: status,
					error: error,
					response: xhr.responseText,
				});
				button.text('Fehler beim Laden');
			},
			complete: function () {
				button.removeClass('loading');
				button.text(settings.load_more_text);
			},
		});
	});

	function renderEpisode(episode, settings) {
		// Box als Link oder Div
		let html = settings.link_type === 'box' ? `<a href="${episode.external_urls.spotify}" target="_blank" class="at_episode at_layout-${settings.layout}">` : `<div class="at_episode at_layout-${settings.layout}">`;

		// Cover
		if (settings.show_cover === 'yes' && episode.images?.[0]?.url) {
			if (settings.link_type === 'cover') {
				html += `<a href="${episode.external_urls.spotify}" target="_blank">`;
			}
			html += '<div class="at_episode-cover">';
			html += `<img src="${episode.images[0].url}" alt="${episode.name}">`;
			html += '</div>';
			if (settings.link_type === 'cover') {
				html += '</a>';
			}
		}

		html += '<div class="at_episode-content">';

		// Title mit dynamischem Tag
		if (settings.show_title === 'yes') {
			const tag = settings.title_tag || 'h3';
			const title = settings.link_type === 'title' ? `<a href="${episode.external_urls.spotify}" target="_blank">${episode.name}</a>` : episode.name;
			html += `<${tag} class="at_episode-title">${title}</${tag}>`;
		}

		// Description mit Limit
		if (settings.show_description === 'yes' && episode.description) {
			let description = episode.description;

			if (settings.description_limit !== 'none' && settings.description_limit_count) {
				if (settings.description_limit === 'characters') {
					if (description.length > settings.description_limit_count) {
						description = description.substring(0, settings.description_limit_count) + '...';
					}
				} else if (settings.description_limit === 'words') {
					const words = description.split(' ');
					if (words.length > settings.description_limit_count) {
						description = words.slice(0, settings.description_limit_count).join(' ') + '...';
					}
				}
			}

			html += `<p class="at_episode-description">${description}</p>`;
		}

		// Duration
		if (settings.show_duration === 'yes' && episode.duration_ms) {
			const duration = Math.round(episode.duration_ms / 60000);
			html += `<div class="at_episode-duration">${duration} Minuten</div>`;
		}

		html += '</div>';

		html += settings.link_type === 'box' ? '</a>' : '</div>';

		return html;
	}
});
