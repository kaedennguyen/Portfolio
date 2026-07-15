(function ($) {
	'use strict';

	var iconMap = {
		good: '✓',
		warning: '!',
		issue: '✕',
		improvement: '→',
		summary: 'ℹ'
	};

	function gradeClass(grade) {
		return 'wag-score-' + grade.toLowerCase();
	}

	function renderFindings(findings) {
		var html = '';
		findings.forEach(function (f) {
			var icon = iconMap[f.type] || '•';
			html += '<div class="wag-finding ' + f.type + '">' +
				'<span class="wag-finding-icon">' + icon + '</span>' +
				'<span>' + $('<div>').text(f.text).html() + '</span>' +
				'</div>';
		});
		return html;
	}

	function renderCategory(key, label, category, note) {
		var grade = category.score >= 90 ? 'A' : category.score >= 80 ? 'B' : category.score >= 70 ? 'C' : category.score >= 60 ? 'D' : 'F';
		var html = '<div class="wag-category" data-cat="' + key + '">' +
			'<div class="wag-category-header">' +
			'<h3>' + label + '</h3>' +
			'<span class="wag-category-score">' + category.score + '/100 (' + grade + ')</span>' +
			'</div>' +
			'<div class="wag-category-body">' +
			renderFindings(category.findings || []) +
			(note ? '<p class="wag-note">' + note + '</p>' : '') +
			'</div>' +
			'</div>';
		return html;
	}

	function renderResults(audit) {
		var html = '';

		html += '<div class="wag-overall">' +
			'<div class="wag-overall-circle ' + gradeClass(audit.overall_grade) + '">' + audit.overall_grade + '</div>' +
			'<div class="wag-overall-meta">' +
			'<h2>Overall Score: ' + audit.overall_score + '/100</h2>' +
			'<p>' + $('<div>').text(audit.url).html() + (audit.from_cache ? ' — cached result' : '') + '</p>' +
			'</div>' +
			'</div>';

		html += renderCategory('seo', 'SEO', audit.seo);
		html += renderCategory('aeo', 'AEO (Answer Engine Optimization)', audit.aeo);
		html += renderCategory('geo', 'GEO (Generative Engine Optimization)', audit.geo);
		html += renderCategory('content', 'Content', audit.content);
		html += renderCategory('design', 'Design & UX', audit.design, audit.design.note);

		if (audit.pagespeed_error) {
			html += '<p class="wag-note">Note: Google PageSpeed data was unavailable (' + $('<div>').text(audit.pagespeed_error).html() + '), so SEO/Design scores rely on structural signals only.</p>';
		}

		return html;
	}

	$(document).on('submit', '#wag-audit-form', function (e) {
		e.preventDefault();

		var $form   = $(this);
		var $btn    = $('#wag-submit-btn');
		var $loading = $('#wag-loading');
		var $error  = $('#wag-error');
		var $results = $('#wag-results');
		var url     = $('#wag-url-input').val();

		$error.hide().empty();
		$results.hide().empty();
		$loading.show();
		$btn.prop('disabled', true);

		$.ajax({
			url: wagAjax.url,
			type: 'POST',
			timeout: 100000, // Slightly above the 90s server-side budget, so we don't cut off a request that's about to finish.
			data: {
				action: 'wag_run_audit',
				nonce: wagAjax.nonce,
				url: url
			}
		}).done(function (response) {
			$loading.hide();
			$btn.prop('disabled', false);

			if (response.success) {
				$results.html(renderResults(response.data.audit)).show();
				// First category open by default.
				$results.find('.wag-category').first().addClass('wag-open');
			} else {
				$error.text((response.data && response.data.message) || 'Something went wrong.').show();
			}
		}).fail(function (jqXHR, textStatus) {
			$loading.hide();
			$btn.prop('disabled', false);
			if (textStatus === 'timeout') {
				$error.text('The audit took too long and timed out. This is usually the target site being slow to respond — try again, or try a different page on the same site.').show();
			} else {
				$error.text('Request failed. Please try again.').show();
			}
		});
	});

	$(document).on('click', '.wag-category-header', function () {
		$(this).closest('.wag-category').toggleClass('wag-open');
	});

})(jQuery);
