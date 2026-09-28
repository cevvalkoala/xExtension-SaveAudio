<?php

declare(strict_types=1);

/**
 * Save Audio extension for FreshRSS.
 *
 * The class name must equal the "entrypoint" value from metadata.json
 * followed by the word "Extension".
 *
 * What this entry point does:
 *  1. Registers a small controller ("saveaudio") that acts as a download proxy
 *     and as a tiny settings endpoint for the browser script.
 *     A proxy is needed because browsers ignore the HTML "download" attribute
 *     for cross-origin URLs, and most podcast hosts do not allow CORS reads.
 *  2. Loads the JavaScript that adds the button to the headline bar.
 *  3. Loads the small stylesheet for that button.
 *  4. Stores the user option "Mark article as read upon downloading the audio file".
 */
final class SaveAudioExtension extends Minz_Extension {

	/** Key under which the option is stored in the user configuration. */
	public const MARK_AS_READ_SETTING_KEY = 'saveaudio_mark_as_read';

	/** Name of the checkbox field in configure.phtml. */
	private const MARK_AS_READ_FORM_FIELD_NAME = 'saveaudio_mark_as_read';

	public function init(): void {
		parent::init();

		// Makes Controllers/saveaudioController.php reachable through "?c=saveaudio&a=...".
		$this->registerController('saveaudio');

		// Files inside the "static" directory of the extension.
		Minz_View::appendScript($this->getFileUrl('saveaudio.js', 'js'));
		Minz_View::appendStyle($this->getFileUrl('saveaudio.css', 'css'));
	}

	/**
	 * Called by FreshRSS when the extension settings page is opened or submitted.
	 * The page itself is configure.phtml.
	 */
	public function handleConfigureAction(): void {
		if (Minz_Request::isPost()) {
			$this->storeMarkAsReadSettingFromRequest();
		}
	}

	/**
	 * Tells whether articles must be marked as read after saving their audio file.
	 * Used by configure.phtml (to tick the box) and by the controller.
	 */
	public function isMarkAsReadEnabled(): bool {
		return FreshRSS_Context::userConf()->attributeBool(self::MARK_AS_READ_SETTING_KEY) === true;
	}

	/**
	 * Reads the checkbox from the submitted form and saves it.
	 * An unticked checkbox is not submitted at all, which correctly gives false.
	 */
	private function storeMarkAsReadSettingFromRequest(): void {
		$isTicked = Minz_Request::paramBoolean(self::MARK_AS_READ_FORM_FIELD_NAME);
		$userConfiguration = FreshRSS_Context::userConf();
		$userConfiguration->_attribute(self::MARK_AS_READ_SETTING_KEY, $isTicked);
		$userConfiguration->save();
	}
}
