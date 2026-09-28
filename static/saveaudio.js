/*
 * Save Audio extension for FreshRSS - browser part.
 *
 * What this script does:
 *  1. Scans every article in the stream for an audio file.
 *  2. Adds a "save audio" button to the headline bar of those articles.
 *  3. On click, builds the file name "Feed Name-Article Name" (without extension),
 *     applies the sanitizing rules, and asks the server-side proxy
 *     (Controllers/saveaudioController.php) to stream the file back as a download.
 *     The server adds the ORIGINAL extension of the audio file (mp3, m4a, ...).
 *
 *  4. If the extension option "Mark article as read upon downloading the audio
 *     file" is ticked, the article is also marked as read.
 *
 * The browser then stores the file in its default download folder.
 */
(function () {
	'use strict';

	// ------------------------------------------------------------------
	// Constants
	// ------------------------------------------------------------------

	const DOWNLOAD_QUERY_STRING = '?c=saveaudio&a=download';
	const SETTINGS_QUERY_STRING = '?c=saveaudio&a=settings';
	const UNREAD_ARTICLE_CSS_CLASS = 'not_read';
	const READ_TOGGLE_SELECTOR = '.flux_header a.read';
	const HIDDEN_FRAME_NAME = 'saveaudio_hidden_frame';
	const BUTTON_CSS_CLASS = 'saveaudio-button';
	const BUTTON_LIST_ITEM_CSS_CLASS = 'saveaudio-item';
	const BUTTON_TITLE_TEXT = 'Save audio file';
	const BUSY_CSS_CLASS = 'saveaudio-busy';
	const BUSY_DURATION_IN_MILLISECONDS = 2000;
	const OBSERVER_DEBOUNCE_IN_MILLISECONDS = 250;
	const FILE_NAME_SEPARATOR = '-';
	const UNKNOWN_FEED_NAME = 'Unknown feed';
	const UNKNOWN_ARTICLE_NAME = 'Untitled';

	/*
	 * Selectors that can point to an audio file inside an article.
	 * FreshRSS renders enclosures as <audio> elements. Some feeds put
	 * <audio>, <source> or plain links into the article body instead.
	 * "data-original" is used by FreshRSS when lazy loading is active.
	 */
	const KNOWN_AUDIO_EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac', 'weba', 'wma'];

	/** Builds two link selectors per extension: "ends with" and "followed by a query string". */
	function buildLinkSelectorsForExtension(extension) {
		return [
			'a[href$=".' + extension + '" i]',
			'a[href*=".' + extension + '?" i]'
		];
	}

	const AUDIO_SOURCE_SELECTORS = [
		'audio[src]',
		'audio[data-original]',
		'audio source[src]',
		'audio source[data-original]',
		'a[href][type^="audio" i]'
	].concat(...KNOWN_AUDIO_EXTENSIONS.map(buildLinkSelectorsForExtension));

	/*
	 * File name rules.
	 * The ORDER matters: "-*" must be replaced before "*".
	 * Each entry is [text to find, replacement].
	 */
	const FILE_NAME_REPLACEMENT_RULES = [
		[':', '-'],
		['?', '.'],
		['|', '-'],
		['\\', '-'],
		['/', '-'],
		['-*', '.'],
		['- ', '-'],
		['*', '.'],
		[',', ''],
		['"', ''],
		['%', 'percent']
	];

	// ------------------------------------------------------------------
	// File name building
	// ------------------------------------------------------------------

	/** Replaces every occurrence of a text (no regular expression needed). */
	function replaceEveryOccurrence(originalText, textToFind, replacementText) {
		return originalText.split(textToFind).join(replacementText);
	}

	/** Applies all rules from FILE_NAME_REPLACEMENT_RULES in their order. */
	function applyFileNameReplacementRules(fileNameCandidate) {
		let currentFileName = fileNameCandidate;
		for (const [textToFind, replacementText] of FILE_NAME_REPLACEMENT_RULES) {
			currentFileName = replaceEveryOccurrence(currentFileName, textToFind, replacementText);
		}
		return currentFileName;
	}

	/** Collapses line breaks and repeated spaces, then trims the text. */
	function normalizeWhitespace(text) {
		return text.replace(/\s+/g, ' ').trim();
	}

	/**
	 * Builds "Feed Name-Article Name" and applies the rules.
	 * The extension is NOT added here. The server adds the original
	 * extension of the audio file after it has followed all redirects.
	 */
	function buildFileNameWithoutExtension(feedName, articleName) {
		const combinedName = normalizeWhitespace(feedName) + FILE_NAME_SEPARATOR
			+ normalizeWhitespace(articleName);
		return applyFileNameReplacementRules(combinedName);
	}

	// ------------------------------------------------------------------
	// Reading data from the article
	// ------------------------------------------------------------------

	/** Returns the trimmed text of the first element found, or an empty string. */
	function readTextOfFirstMatch(rootElement, selectorList) {
		for (const selector of selectorList) {
			const foundElement = rootElement.querySelector(selector);
			if (foundElement && foundElement.textContent.trim() !== '') {
				return foundElement.textContent.trim();
			}
		}
		return '';
	}

	/** Reads the feed name from the headline bar, or from the sidebar as fallback. */
	function readFeedName(articleElement) {
		const nameFromHeadline = readTextOfFirstMatch(articleElement, [
			'.flux_header .website .websiteName',
			'.flux_header .website'
		]);
		if (nameFromHeadline !== '') {
			return nameFromHeadline;
		}
		const feedIdentifier = articleElement.getAttribute('data-feed');
		if (feedIdentifier) {
			const sidebarElement = document.querySelector('#f_' + feedIdentifier + ' .item-title');
			if (sidebarElement && sidebarElement.textContent.trim() !== '') {
				return sidebarElement.textContent.trim();
			}
		}
		return UNKNOWN_FEED_NAME;
	}

	/** Reads the article title from the headline bar or the article body. */
	function readArticleName(articleElement) {
		const articleName = readTextOfFirstMatch(articleElement, [
			'.flux_header .title',
			'.flux_content .title'
		]);
		return articleName !== '' ? articleName : UNKNOWN_ARTICLE_NAME;
	}

	/** Converts a raw attribute value into an absolute http(s) URL, or null. */
	function convertToAbsoluteHttpUrl(rawUrl) {
		if (!rawUrl) {
			return null;
		}
		try {
			const absoluteUrl = new URL(rawUrl, document.baseURI);
			const isHttp = absoluteUrl.protocol === 'http:' || absoluteUrl.protocol === 'https:';
			return isHttp ? absoluteUrl.href : null;
		} catch (error) {
			return null;
		}
	}

	/** Reads the URL from an audio-related element. */
	function readUrlFromAudioElement(audioRelatedElement) {
		const rawUrl = audioRelatedElement.getAttribute('src')
			|| audioRelatedElement.getAttribute('data-original')
			|| audioRelatedElement.getAttribute('href');
		return convertToAbsoluteHttpUrl(rawUrl);
	}

	/**
	 * Reads the file extension from the URL path (query string ignored).
	 * Returns an empty string when the URL has no known audio extension.
	 * This is only a hint. The server prefers the extension of the final URL.
	 */
	function readAudioExtensionFromUrl(audioUrl) {
		try {
			const pathName = new URL(audioUrl).pathname;
			const match = pathName.match(/\.([a-z0-9]{2,5})$/i);
			const extension = match ? match[1].toLowerCase() : '';
			return KNOWN_AUDIO_EXTENSIONS.indexOf(extension) !== -1 ? extension : '';
		} catch (error) {
			return '';
		}
	}

	/** Returns the URL of the first audio file inside the article, or null. */
	function findAudioUrlInArticle(articleElement) {
		const contentElement = articleElement.querySelector('.flux_content') || articleElement;
		for (const selector of AUDIO_SOURCE_SELECTORS) {
			const matchingElements = contentElement.querySelectorAll(selector);
			for (const matchingElement of matchingElements) {
				const audioUrl = readUrlFromAudioElement(matchingElement);
				if (audioUrl !== null) {
					return audioUrl;
				}
			}
		}
		return null;
	}

	// ------------------------------------------------------------------
	// Button creation
	// ------------------------------------------------------------------

	/** Creates the download icon as inline SVG (inherits the text color). */
	function createDownloadIconElement() {
		const svgNamespace = 'http://www.w3.org/2000/svg';
		const svgElement = document.createElementNS(svgNamespace, 'svg');
		svgElement.setAttribute('viewBox', '0 0 16 16');
		svgElement.setAttribute('width', '16');
		svgElement.setAttribute('height', '16');
		svgElement.setAttribute('aria-hidden', 'true');
		const pathElement = document.createElementNS(svgNamespace, 'path');
		pathElement.setAttribute('d', 'M8 1v9M4 6.5 8 10.5l4-4M2 14h12');
		pathElement.setAttribute('fill', 'none');
		pathElement.setAttribute('stroke', 'currentColor');
		pathElement.setAttribute('stroke-width', '1.8');
		pathElement.setAttribute('stroke-linecap', 'round');
		pathElement.setAttribute('stroke-linejoin', 'round');
		svgElement.appendChild(pathElement);
		return svgElement;
	}

	/** Creates the list item that holds the button. */
	function createButtonListItem() {
		const listItemElement = document.createElement('li');
		listItemElement.className = 'item ' + BUTTON_LIST_ITEM_CSS_CLASS;

		const buttonElement = document.createElement('a');
		buttonElement.className = 'item-element ' + BUTTON_CSS_CLASS;
		buttonElement.href = '#';
		buttonElement.setAttribute('role', 'button');
		buttonElement.title = BUTTON_TITLE_TEXT;
		buttonElement.setAttribute('aria-label', BUTTON_TITLE_TEXT);
		buttonElement.appendChild(createDownloadIconElement());

		listItemElement.appendChild(buttonElement);
		return listItemElement;
	}

	/** Inserts the button before the "open link" item, or at the end of the headline bar. */
	function insertButtonIntoHeadline(headlineElement) {
		const buttonListItem = createButtonListItem();
		const linkItem = headlineElement.querySelector('.item.link');
		if (linkItem && linkItem.parentNode === headlineElement) {
			headlineElement.insertBefore(buttonListItem, linkItem);
		} else {
			headlineElement.appendChild(buttonListItem);
		}
	}

	/** Adds the button to one article if it contains audio and has no button yet. */
	function processArticle(articleElement) {
		const headlineElement = articleElement.querySelector('.flux_header');
		if (!headlineElement || headlineElement.querySelector('.' + BUTTON_CSS_CLASS)) {
			return;
		}
		if (findAudioUrlInArticle(articleElement) === null) {
			return;
		}
		insertButtonIntoHeadline(headlineElement);
	}

	/** Processes every article currently in the page. */
	function processAllArticles() {
		document.querySelectorAll('.flux').forEach(processArticle);
	}

	// ------------------------------------------------------------------
	// Download request
	// ------------------------------------------------------------------

	/** Reads the CSRF token that FreshRSS exposes to its scripts. */
	function readCsrfToken() {
		if (typeof context !== 'undefined' && context && context.csrf) {
			return context.csrf;
		}
		if (window.context && window.context.csrf) {
			return window.context.csrf;
		}
		const tokenInput = document.querySelector('input[name="_csrf"]');
		return tokenInput ? tokenInput.value : '';
	}

	/** Shows a server error message that arrived inside the hidden frame. */
	function reportErrorFromHiddenFrame(frameElement) {
		try {
			const frameText = frameElement.contentDocument.body.textContent.trim();
			if (frameText !== '') {
				window.alert('Save Audio: ' + frameText);
			}
		} catch (error) {
			// Nothing to show. A finished download does not expose a document.
		}
	}

	/** Creates the hidden frame once. The download response lands here, so the page never navigates. */
	function ensureHiddenFrame() {
		let frameElement = document.querySelector('iframe[name="' + HIDDEN_FRAME_NAME + '"]');
		if (!frameElement) {
			frameElement = document.createElement('iframe');
			frameElement.name = HIDDEN_FRAME_NAME;
			frameElement.style.display = 'none';
			frameElement.addEventListener('load', function () {
				reportErrorFromHiddenFrame(frameElement);
			});
			document.body.appendChild(frameElement);
		}
		return frameElement;
	}

	/** Creates one hidden text input for the download form. */
	function createHiddenInput(fieldName, fieldValue) {
		const inputElement = document.createElement('input');
		inputElement.type = 'hidden';
		inputElement.name = fieldName;
		inputElement.value = fieldValue;
		return inputElement;
	}

	/** Submits a POST form into the hidden frame. */
	function submitDownloadRequest(audioUrl, fileNameWithoutExtension, extensionHint) {
		ensureHiddenFrame();

		const formElement = document.createElement('form');
		formElement.method = 'POST';
		formElement.action = window.location.pathname + DOWNLOAD_QUERY_STRING;
		formElement.target = HIDDEN_FRAME_NAME;
		formElement.style.display = 'none';
		formElement.appendChild(createHiddenInput('_csrf', readCsrfToken()));
		formElement.appendChild(createHiddenInput('url', audioUrl));
		formElement.appendChild(createHiddenInput('filename', fileNameWithoutExtension));
		formElement.appendChild(createHiddenInput('extension', extensionHint));

		document.body.appendChild(formElement);
		formElement.submit();
		document.body.removeChild(formElement);
	}

	/** Gives short visual feedback after a click. */
	function showBusyFeedback(buttonElement) {
		buttonElement.classList.add(BUSY_CSS_CLASS);
		window.setTimeout(function () {
			buttonElement.classList.remove(BUSY_CSS_CLASS);
		}, BUSY_DURATION_IN_MILLISECONDS);
	}

	// ------------------------------------------------------------------
	// Mark as read (optional, controlled by the extension settings)
	// ------------------------------------------------------------------

	/** Asks the server whether "Mark article as read upon downloading" is ticked. */
	async function fetchMarkAsReadSetting() {
		try {
			const response = await fetch(window.location.pathname + SETTINGS_QUERY_STRING, {
				credentials: 'same-origin',
				cache: 'no-store'
			});
			if (!response.ok) {
				return false;
			}
			const settings = await response.json();
			return settings.markAsRead === true;
		} catch (error) {
			return false;
		}
	}

	function isArticleUnread(articleElement) {
		return articleElement.classList.contains(UNREAD_ARTICLE_CSS_CLASS);
	}

	/**
	 * Clicks the normal "mark as read" toggle of the article.
	 * This reuses the FreshRSS handler, so the page and the counters update as usual.
	 */
	function markArticleAsReadUsingToggle(articleElement) {
		const toggleElement = articleElement.querySelector(READ_TOGGLE_SELECTOR);
		if (!toggleElement) {
			return false;
		}
		toggleElement.click();
		return true;
	}

	/** Fallback: calls the FreshRSS function directly when no toggle was found. */
	function markArticleAsReadUsingFreshRssFunction(articleElement) {
		if (typeof mark_read !== 'function') {
			return false;
		}
		try {
			mark_read(articleElement, true, false);
			return true;
		} catch (error) {
			return false;
		}
	}

	/** Marks the article as read, but only if it is unread (a read article must not be toggled back). */
	function markArticleAsReadIfUnread(articleElement) {
		if (!isArticleUnread(articleElement)) {
			return;
		}
		if (markArticleAsReadUsingToggle(articleElement)) {
			return;
		}
		markArticleAsReadUsingFreshRssFunction(articleElement);
	}

	/** Reads the option at click time, and marks the article as read when it is ticked. */
	async function markArticleAsReadWhenOptionIsEnabled(articleElement) {
		const optionIsEnabled = await fetchMarkAsReadSetting();
		if (optionIsEnabled) {
			markArticleAsReadIfUnread(articleElement);
		}
	}

	// ------------------------------------------------------------------
	// Event handling
	// ------------------------------------------------------------------

	/**
	 * Handles clicks on the button.
	 * The listener runs in the capture phase and stops the event, so
	 * FreshRSS does not also open or close the article.
	 */
	function handleDocumentClick(clickEvent) {
		const buttonElement = clickEvent.target.closest ? clickEvent.target.closest('.' + BUTTON_CSS_CLASS) : null;
		if (!buttonElement) {
			return;
		}
		clickEvent.preventDefault();
		clickEvent.stopPropagation();

		const articleElement = buttonElement.closest('.flux');
		if (!articleElement) {
			return;
		}
		const audioUrl = findAudioUrlInArticle(articleElement);
		if (audioUrl === null) {
			window.alert('Save Audio: no audio file found in this article.');
			return;
		}
		const fileNameWithoutExtension = buildFileNameWithoutExtension(readFeedName(articleElement), readArticleName(articleElement));
		const extensionHint = readAudioExtensionFromUrl(audioUrl);
		showBusyFeedback(buttonElement);
		submitDownloadRequest(audioUrl, fileNameWithoutExtension, extensionHint);
		markArticleAsReadWhenOptionIsEnabled(articleElement);
	}

	/** Watches for articles added later (infinite scroll, refresh) and processes them. */
	function observeNewArticles() {
		let pendingTimerIdentifier = null;
		const observer = new MutationObserver(function () {
			if (pendingTimerIdentifier !== null) {
				return;
			}
			pendingTimerIdentifier = window.setTimeout(function () {
				pendingTimerIdentifier = null;
				processAllArticles();
			}, OBSERVER_DEBOUNCE_IN_MILLISECONDS);
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}

	function initialize() {
		document.addEventListener('click', handleDocumentClick, true);
		processAllArticles();
		observeNewArticles();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize);
	} else {
		initialize();
	}
})();
