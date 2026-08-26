/**
 * Plugin usage survey.
 *
 * Handles the survey modal, the local answered flag request and the relay of
 * the selected answer to the Melapress feedback server.
 *
 * @since 2.4.0
 */
(() => {
	'use strict';

	const surveyModal = document.getElementById('mfm-usage-survey');
	const followUpModal = document.getElementById('mfm-usage-survey-follow-up');

	// Bail on browsers without Popover API support, showPopover() would throw there.
	if (
		!surveyModal ||
		!followUpModal ||
		typeof mfmUsageSurvey === 'undefined' ||
		typeof surveyModal.showPopover !== 'function'
	) {
		return;
	}

	const surveyForm = surveyModal.querySelector('.mfm-usage-survey-form');
	const validationMessage = surveyModal.querySelector('.mfm-usage-survey-validation');
	const submitButton = surveyModal.querySelector('.mfm-usage-survey-submit');

	/**
	 * Show the survey and move keyboard focus into it.
	 *
	 * @return {void}
	 */
	const openSurvey = () => {
		if (!surveyModal.matches(':popover-open')) {
			surveyModal.showPopover();
			surveyModal.focus({ preventScroll: true });
		}
	};

	/**
	 * Add the survey notification to the plugin menu after initial setup.
	 *
	 * @return {void}
	 */
	const addMenuNotification = () => {
		const menuName = document.querySelector(
			'#toplevel_page_file-monitor-admin .wp-menu-name'
		);

		if (!menuName || menuName.querySelector('.awaiting-mod')) {
			return;
		}

		const badge = document.createElement('span');
		badge.className = 'awaiting-mod mfm-usage-survey-notice';
		badge.style.position = 'absolute';
		badge.style.marginLeft = '3px';
		badge.textContent = '1';
		menuName.append(' ', badge);
	};

	/**
	 * Send a POST request and return the parsed response.
	 *
	 * @param {string} url Request URL.
	 * @param {Object} data Request body fields.
	 *
	 * @return {Promise<Object>} Parsed JSON response.
	 */
	const sendRequest = async (url, data) => {
		const formData = new FormData();

		for (const [key, value] of Object.entries(data)) {
			formData.append(key, value);
		}

		const response = await fetch(url, { method: 'POST', body: formData });

		try {
			return await response.json();
		} catch {
			return {};
		}
	};

	/**
	 * Store the local answered flag. The answer itself is not stored.
	 *
	 * @return {Promise<Object>} Local AJAX response.
	 */
	const storeAnsweredFlag = () =>
		sendRequest(mfmUsageSurvey.ajaxUrl, {
			action: 'mfm_usage_survey_answered',
			nonce: mfmUsageSurvey.nonce,
		});

	/**
	 * Relay the answer to the Melapress feedback server, nonce first.
	 *
	 * @param {string} answer Selected survey answer.
	 *
	 * @return {Promise<void>} Resolves after the answer request completes.
	 */
	const sendAnswerToFeedbackServer = async (answer) => {
		const nonceResponse = await sendRequest(
			`${mfmUsageSurvey.remoteUrl}/?rest_route=/deactivation-feedback-server/v1/get-mfm-usage-survey-nonce`,
			{}
		);

		if (!nonceResponse.nonce) {
			return;
		}

		await sendRequest(
			`${mfmUsageSurvey.remoteUrl}/?rest_route=/deactivation-feedback-server/v1/submit-mfm-usage-survey`,
			{
				nonce: nonceResponse.nonce,
				answer,
				version: mfmUsageSurvey.version,
				site: mfmUsageSurvey.site,
			}
		);
	};

	for (const closeButton of document.querySelectorAll('.mfm-usage-survey-close')) {
		closeButton.addEventListener('click', (clickEvent) => {
			clickEvent.target.closest('.mfm-usage-survey').hidePopover();
		});
	}

	surveyForm.addEventListener('submit', async (submitEvent) => {
		submitEvent.preventDefault();

		const selectedAnswer = surveyForm.querySelector(
			'input[name="mfm-usage-survey-answer"]:checked'
		);

		if (!selectedAnswer) {
			validationMessage.hidden = false;
			return;
		}

		validationMessage.hidden = true;
		submitButton.disabled = true;
		surveyModal.classList.add('mfm-usage-survey-submitting');

		try {
			const localResponse = await storeAnsweredFlag();

			if (localResponse.success && mfmUsageSurvey.clearMenuNotification) {
				const surveyNotification = document.querySelector(
					'#toplevel_page_file-monitor-admin .awaiting-mod'
				);

				if (surveyNotification) {
					surveyNotification.remove();
				}
			}
		} catch {
			// Silent fail, the survey still moves on to the follow up modal.
		}

		try {
			await sendAnswerToFeedbackServer(selectedAnswer.value);
		} catch {
			// Silent fail, the answer is lost and the user is not asked again.
		}

		surveyModal.hidePopover();
		followUpModal.showPopover();
	});

	document.addEventListener('mfmSetupWizardCompleted', () => {
		addMenuNotification();
		openSurvey();
	});

	if (mfmUsageSurvey.autoOpen) {
		openSurvey();
	}
})();
