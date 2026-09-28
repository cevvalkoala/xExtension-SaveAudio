<?php

declare(strict_types=1);

/**
 * Server part of the Save Audio extension.
 *
 * Actions:
 *  - "?c=saveaudio&a=download": download proxy (described below).
 *  - "?c=saveaudio&a=settings": returns the extension options as JSON.
 *
 * Download flow:
 *  1. The browser POSTs the audio URL, the file name WITHOUT extension and an
 *     optional extension hint to this action.
 *  2. This action downloads the audio from the podcast host and streams it back
 *     with a "Content-Disposition: attachment" header.
 *  3. The browser saves the stream under the final file name in its default
 *     download location. No "Save as" dialog is needed.
 *
 * File extension logic (first match wins):
 *  1. The extension in the path of the FINAL URL, after all redirects.
 *  2. The extension that belongs to the Content-Type of the response.
 *  3. The extension hint that the browser found in the ORIGINAL URL.
 *  4. "mp3" as last resort, so the file never has no extension.
 *
 * Security measures (a download proxy can otherwise be abused for SSRF):
 *  - Only logged-in users can use it.
 *  - Only POST requests with a valid CSRF token are accepted.
 *  - Only http and https URLs on the ports 80 and 443 are accepted.
 *  - Every host (including every redirect target) must resolve to a public IP address.
 *  - The resolved IP address is pinned for the request to prevent DNS rebinding.
 */
final class FreshExtension_saveaudio_Controller extends FreshRSS_ActionController {

	private const MAXIMUM_NUMBER_OF_REDIRECTS = 8;
	private const CONNECTION_TIMEOUT_IN_SECONDS = 15;
	private const MINIMUM_SPEED_IN_BYTES_PER_SECOND = 1024;
	private const MAXIMUM_SECONDS_BELOW_MINIMUM_SPEED = 60;
	private const MAXIMUM_BASE_NAME_LENGTH_IN_BYTES = 200;
	private const DEFAULT_BASE_NAME = 'audio';
	private const FALLBACK_EXTENSION = 'mp3';
	private const USER_AGENT = 'FreshRSS-SaveAudio/1.0';

	/** Extensions that are accepted as audio file extensions. */
	private const ALLOWED_EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac', 'weba', 'wma', 'mp4'];

	/** Maps a Content-Type (without parameters) to a file extension. */
	private const EXTENSION_BY_CONTENT_TYPE = [
		'audio/mpeg' => 'mp3',
		'audio/mp3' => 'mp3',
		'audio/x-mpeg' => 'mp3',
		'audio/mp4' => 'm4a',
		'audio/x-m4a' => 'm4a',
		'audio/m4a' => 'm4a',
		'audio/aac' => 'aac',
		'audio/aacp' => 'aac',
		'audio/ogg' => 'ogg',
		'application/ogg' => 'ogg',
		'audio/opus' => 'opus',
		'audio/wav' => 'wav',
		'audio/x-wav' => 'wav',
		'audio/wave' => 'wav',
		'audio/flac' => 'flac',
		'audio/x-flac' => 'flac',
		'audio/webm' => 'weba',
		'audio/x-ms-wma' => 'wma',
	];

	/**
	 * Entry point: "?c=saveaudio&a=download".
	 */
	public function downloadAction(): void {
		$this->rejectRequestUnlessItIsAllowed();

		$audioUrl = $this->readAudioUrlFromRequest();
		$baseName = $this->readSafeBaseNameFromRequest();
		$extensionHint = $this->readExtensionHintFromRequest();

		$this->prepareEnvironmentForLongStreaming();
		$this->streamAudioAndFollowRedirects($audioUrl, $baseName, $extensionHint);

		// The view must never be rendered after streaming.
		exit;
	}

	/**
	 * Entry point: "?c=saveaudio&a=settings".
	 * Returns the extension options as JSON, so the browser script always sees
	 * the current value without a page reload.
	 */
	public function settingsAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			$this->terminateWithError(403, 'Forbidden: please log in.');
		}
		$markAsReadIsEnabled = FreshRSS_Context::userConf()
			->attributeBool(SaveAudioExtension::MARK_AS_READ_SETTING_KEY) === true;

		header('Content-Type: application/json; charset=UTF-8');
		header('Cache-Control: no-store');
		echo json_encode(['markAsRead' => $markAsReadIsEnabled]);
		exit;
	}

	// ------------------------------------------------------------------
	// Request validation
	// ------------------------------------------------------------------

	/**
	 * Stops the request unless the user is logged in, uses POST and sends a valid CSRF token.
	 */
	private function rejectRequestUnlessItIsAllowed(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			$this->terminateWithError(403, 'Forbidden: please log in.');
		}
		if (!Minz_Request::isPost()) {
			$this->terminateWithError(405, 'Method not allowed: use POST.');
		}
		if (!$this->isCsrfTokenValid()) {
			$this->terminateWithError(403, 'Forbidden: invalid CSRF token.');
		}
	}

	/**
	 * Checks the CSRF token. Uses the FreshRSS helper when it exists and
	 * falls back to a direct comparison with the session token.
	 */
	private function isCsrfTokenValid(): bool {
		if (method_exists('FreshRSS_Auth', 'isCsrfOk')) {
			return (bool)FreshRSS_Auth::isCsrfOk();
		}
		$submittedToken = Minz_Request::paramString('_csrf');
		$expectedToken = Minz_Session::paramString('csrf');
		return $expectedToken !== '' && hash_equals($expectedToken, $submittedToken);
	}

	/**
	 * Reads the audio URL from the request and validates its basic shape.
	 */
	private function readAudioUrlFromRequest(): string {
		$audioUrl = trim(Minz_Request::paramString('url'));
		if ($audioUrl === '' || filter_var($audioUrl, FILTER_VALIDATE_URL) === false) {
			$this->terminateWithError(400, 'Bad request: invalid audio URL.');
		}
		return $audioUrl;
	}

	/**
	 * Reads the file name (without extension) and removes everything that could
	 * break the HTTP header or the file system. The main rules were already
	 * applied in the browser. This is only a second safety net.
	 */
	private function readSafeBaseNameFromRequest(): string {
		$rawBaseName = Minz_Request::paramString('filename');
		$baseNameWithoutControlCharacters = $this->removeControlCharacters($rawBaseName);
		$baseNameWithoutPathSeparators = str_replace(['/', '\\'], '-', $baseNameWithoutControlCharacters);
		$trimmedBaseName = trim($baseNameWithoutPathSeparators);
		$shortenedBaseName = $this->shortenToMaximumByteLength($trimmedBaseName);
		return $shortenedBaseName !== '' ? $shortenedBaseName : self::DEFAULT_BASE_NAME;
	}

	/**
	 * Reads the extension hint from the browser. Unknown values are ignored.
	 */
	private function readExtensionHintFromRequest(): string {
		$hint = strtolower(trim(Minz_Request::paramString('extension')));
		return in_array($hint, self::ALLOWED_EXTENSIONS, true) ? $hint : '';
	}

	private function removeControlCharacters(string $text): string {
		// The pattern only touches ASCII bytes, so UTF-8 characters stay intact.
		return preg_replace('/[\x00-\x1F\x7F]+/', '', $text) ?? '';
	}

	private function shortenToMaximumByteLength(string $text): string {
		if (strlen($text) <= self::MAXIMUM_BASE_NAME_LENGTH_IN_BYTES) {
			return $text;
		}
		// mb_strcut cuts at a byte limit without splitting a UTF-8 character.
		if (function_exists('mb_strcut')) {
			return mb_strcut($text, 0, self::MAXIMUM_BASE_NAME_LENGTH_IN_BYTES, 'UTF-8');
		}
		return substr($text, 0, self::MAXIMUM_BASE_NAME_LENGTH_IN_BYTES);
	}

	// ------------------------------------------------------------------
	// File extension detection
	// ------------------------------------------------------------------

	/**
	 * Chooses the extension using the priority order from the class comment.
	 */
	private function determineFileExtension(string $finalUrl, ?string $contentType, string $extensionHint): string {
		$extensionFromUrl = $this->readExtensionFromUrlPath($finalUrl);
		if ($extensionFromUrl !== '') {
			return $extensionFromUrl;
		}
		$extensionFromContentType = $this->readExtensionFromContentType($contentType);
		if ($extensionFromContentType !== '') {
			return $extensionFromContentType;
		}
		if ($extensionHint !== '') {
			return $extensionHint;
		}
		return self::FALLBACK_EXTENSION;
	}

	/**
	 * Returns the allowed extension at the end of the URL path (query string ignored), or ''.
	 */
	private function readExtensionFromUrlPath(string $url): string {
		$path = (string)parse_url($url, PHP_URL_PATH);
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($extension, self::ALLOWED_EXTENSIONS, true) ? $extension : '';
	}

	/**
	 * Returns the extension that belongs to a Content-Type, or ''.
	 */
	private function readExtensionFromContentType(?string $contentType): string {
		if ($contentType === null) {
			return '';
		}
		return self::EXTENSION_BY_CONTENT_TYPE[$contentType] ?? '';
	}

	// ------------------------------------------------------------------
	// Streaming
	// ------------------------------------------------------------------

	/**
	 * Prepares PHP for a potentially long download.
	 */
	private function prepareEnvironmentForLongStreaming(): void {
		// Podcast episodes can be large and slow. Do not let PHP stop the script.
		set_time_limit(0);
		// Release the session lock so other FreshRSS requests are not blocked.
		if (function_exists('session_write_close')) {
			session_write_close();
		}
		// Remove any output buffers so data goes to the browser immediately.
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
	}

	/**
	 * Requests the URL and follows redirects manually.
	 * Manual handling is required to validate every hop.
	 * Podcast URLs often pass through several tracking redirects.
	 */
	private function streamAudioAndFollowRedirects(string $startUrl, string $baseName, string $extensionHint): void {
		$currentUrl = $startUrl;
		for ($redirectCount = 0; $redirectCount <= self::MAXIMUM_NUMBER_OF_REDIRECTS; $redirectCount++) {
			$nextRedirectUrl = $this->requestOnceAndStreamOrReturnRedirect($currentUrl, $baseName, $extensionHint);
			if ($nextRedirectUrl === null) {
				return; // Streaming finished.
			}
			$currentUrl = $nextRedirectUrl;
		}
		$this->terminateWithError(502, 'Bad gateway: too many redirects.');
	}

	/**
	 * Performs one HTTP request.
	 *
	 * @return string|null The absolute redirect URL, or null when the audio was streamed completely.
	 */
	private function requestOnceAndStreamOrReturnRedirect(string $url, string $baseName, string $extensionHint): ?string {
		$urlParts = $this->parseAndValidateUrl($url);
		$pinnedIpAddress = $this->resolveHostToPublicIpAddress($urlParts['host']);
		if ($pinnedIpAddress === null) {
			$this->terminateWithError(400, 'Bad request: the host is not a public address.');
		}

		// Shared state between the cURL callbacks.
		$responseState = [
			'statusCode' => 0,
			'redirectLocation' => null,
			'contentLength' => null,
			'contentType' => null,
			'headersSent' => false,
		];

		$curlHandle = curl_init($url);
		if ($curlHandle === false) {
			$this->terminateWithError(500, 'Server error: cURL could not start.');
		}

		curl_setopt_array($curlHandle, $this->buildCurlOptions(
			$urlParts,
			$pinnedIpAddress,
			$this->buildHeaderCallback($responseState),
			$this->buildBodyCallback($responseState, $url, $baseName, $extensionHint)
		));

		curl_exec($curlHandle);
		curl_close($curlHandle);

		return $this->decideResultAfterRequest($responseState, $url);
	}

	/**
	 * @param array{scheme:string,host:string,port:int} $urlParts
	 * @return array<int,mixed>
	 */
	private function buildCurlOptions(array $urlParts, ?string $pinnedIpAddress, callable $headerCallback, callable $bodyCallback): array {
		$options = [
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => self::CONNECTION_TIMEOUT_IN_SECONDS,
			CURLOPT_TIMEOUT => 0, // No total limit. The low-speed guard below stops stalled transfers.
			CURLOPT_LOW_SPEED_LIMIT => self::MINIMUM_SPEED_IN_BYTES_PER_SECOND,
			CURLOPT_LOW_SPEED_TIME => self::MAXIMUM_SECONDS_BELOW_MINIMUM_SPEED,
			CURLOPT_USERAGENT => self::USER_AGENT,
			CURLOPT_HTTPHEADER => ['Accept: audio/*,*/*;q=0.8'],
			CURLOPT_HEADERFUNCTION => $headerCallback,
			CURLOPT_WRITEFUNCTION => $bodyCallback,
		];
		// Pin the validated IP address for host names (protects against DNS rebinding).
		if ($pinnedIpAddress !== null && filter_var($urlParts['host'], FILTER_VALIDATE_IP) === false) {
			$options[CURLOPT_RESOLVE] = [$urlParts['host'] . ':' . $urlParts['port'] . ':' . $pinnedIpAddress];
		}
		return $options;
	}

	/**
	 * Builds the callback that reads the response headers line by line.
	 *
	 * @param array<string,mixed> $responseState
	 */
	private function buildHeaderCallback(array &$responseState): callable {
		return function ($curlHandle, string $headerLine) use (&$responseState): int {
			$trimmedLine = trim($headerLine);

			// A status line starts a new response (for example after "100 Continue").
			if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $trimmedLine, $matches) === 1) {
				$responseState['statusCode'] = (int)$matches[1];
				$responseState['redirectLocation'] = null;
				$responseState['contentLength'] = null;
				$responseState['contentType'] = null;
			} elseif (stripos($trimmedLine, 'location:') === 0) {
				$responseState['redirectLocation'] = trim(substr($trimmedLine, strlen('location:')));
			} elseif (stripos($trimmedLine, 'content-length:') === 0) {
				$lengthText = trim(substr($trimmedLine, strlen('content-length:')));
				$responseState['contentLength'] = ctype_digit($lengthText) ? $lengthText : null;
			} elseif (stripos($trimmedLine, 'content-type:') === 0) {
				$responseState['contentType'] = $this->normalizeContentType(substr($trimmedLine, strlen('content-type:')));
			}
			return strlen($headerLine);
		};
	}

	/**
	 * Lowercases a Content-Type and removes parameters such as "; charset=...".
	 */
	private function normalizeContentType(string $rawContentType): string {
		$typeWithoutParameters = explode(';', $rawContentType)[0];
		return strtolower(trim($typeWithoutParameters));
	}

	/**
	 * Builds the callback that forwards body chunks to the browser.
	 *
	 * @param array<string,mixed> $responseState
	 */
	private function buildBodyCallback(array &$responseState, string $requestUrl, string $baseName, string $extensionHint): callable {
		return function ($curlHandle, string $chunk) use (&$responseState, $requestUrl, $baseName, $extensionHint): int {
			$statusCode = (int)$responseState['statusCode'];

			// Redirect bodies are tiny and useless. Discard them.
			if ($statusCode >= 300 && $statusCode < 400) {
				return strlen($chunk);
			}
			// Anything except 200 is an error. Returning 0 aborts the transfer.
			if ($statusCode !== 200) {
				return 0;
			}
			if (!$responseState['headersSent']) {
				$extension = $this->determineFileExtension($requestUrl, $responseState['contentType'], $extensionHint);
				$this->sendDownloadHeaders($baseName . '.' . $extension, $responseState['contentLength']);
				$responseState['headersSent'] = true;
			}
			echo $chunk;
			flush();
			return strlen($chunk);
		};
	}

	/**
	 * Sends the headers that make the browser save the stream as a file.
	 */
	private function sendDownloadHeaders(string $fileName, ?string $contentLength): void {
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: ' . $this->buildContentDispositionValue($fileName));
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: no-store');
		// Tells nginx-style reverse proxies not to buffer the whole file.
		header('X-Accel-Buffering: no');
		if ($contentLength !== null) {
			header('Content-Length: ' . $contentLength);
		}
	}

	/**
	 * Builds the Content-Disposition value with an ASCII fallback (filename)
	 * and the full UTF-8 name (filename*), so names with non-ASCII letters survive.
	 */
	private function buildContentDispositionValue(string $fileName): string {
		$asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $fileName) ?? 'audio.mp3';
		$asciiFallback = str_replace(['"', '\\', '%'], '_', $asciiFallback);
		return 'attachment; filename="' . $asciiFallback . '"; filename*=UTF-8\'\'' . rawurlencode($fileName);
	}

	/**
	 * Decides what to do after cURL finished.
	 *
	 * @param array<string,mixed> $responseState
	 * @return string|null Redirect URL, or null when the audio was streamed.
	 */
	private function decideResultAfterRequest(array $responseState, string $requestUrl): ?string {
		$statusCode = (int)$responseState['statusCode'];

		$isRedirect = in_array($statusCode, [301, 302, 303, 307, 308], true);
		if ($isRedirect && is_string($responseState['redirectLocation']) && $responseState['redirectLocation'] !== '') {
			return $this->buildAbsoluteUrl($requestUrl, $responseState['redirectLocation']);
		}
		if ($statusCode === 200 && $responseState['headersSent']) {
			return null;
		}
		if ($statusCode === 200) {
			$this->terminateWithError(502, 'Bad gateway: the audio file is empty.');
		}
		$this->terminateWithError(502, 'Bad gateway: the audio host answered with status ' . $statusCode . '.');
		return null;
	}

	// ------------------------------------------------------------------
	// URL and network safety helpers
	// ------------------------------------------------------------------

	/**
	 * Parses the URL and allows only http/https on the default ports.
	 *
	 * @return array{scheme:string,host:string,port:int}
	 */
	private function parseAndValidateUrl(string $url): array {
		$parts = parse_url($url);
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$host = (string)($parts['host'] ?? '');
		if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
			$this->terminateWithError(400, 'Bad request: only http and https URLs are allowed.');
		}
		$defaultPort = $scheme === 'https' ? 443 : 80;
		$port = (int)($parts['port'] ?? $defaultPort);
		if ($port !== 80 && $port !== 443) {
			$this->terminateWithError(400, 'Bad request: only the ports 80 and 443 are allowed.');
		}
		return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
	}

	/**
	 * Resolves a host name and returns one IP address, but only when
	 * every resolved address is public. Returns null otherwise.
	 */
	private function resolveHostToPublicIpAddress(string $host): ?string {
		$hostWithoutBrackets = trim($host, '[]');

		// The host is already an IP literal.
		if (filter_var($hostWithoutBrackets, FILTER_VALIDATE_IP) !== false) {
			return $this->isPublicIpAddress($hostWithoutBrackets) ? $hostWithoutBrackets : null;
		}

		$resolvedAddresses = gethostbynamel($hostWithoutBrackets);
		if ($resolvedAddresses === false || $resolvedAddresses === []) {
			return null;
		}
		foreach ($resolvedAddresses as $resolvedAddress) {
			if (!$this->isPublicIpAddress($resolvedAddress)) {
				return null;
			}
		}
		return $resolvedAddresses[0];
	}

	private function isPublicIpAddress(string $ipAddress): bool {
		return filter_var(
			$ipAddress,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		) !== false;
	}

	/**
	 * Converts a Location header value into an absolute URL.
	 */
	private function buildAbsoluteUrl(string $baseUrl, string $location): string {
		// Already absolute.
		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
			return $location;
		}
		$baseParts = parse_url($baseUrl);
		$scheme = (string)($baseParts['scheme'] ?? 'https');
		$authority = (string)($baseParts['host'] ?? '');
		if (isset($baseParts['port'])) {
			$authority .= ':' . $baseParts['port'];
		}
		// Protocol-relative ("//host/path").
		if (strpos($location, '//') === 0) {
			return $scheme . ':' . $location;
		}
		// Root-relative ("/path").
		if (strpos($location, '/') === 0) {
			return $scheme . '://' . $authority . $location;
		}
		// Path-relative ("file.mp3").
		$basePath = (string)($baseParts['path'] ?? '/');
		$baseDirectory = substr($basePath, 0, (int)strrpos($basePath, '/') + 1);
		return $scheme . '://' . $authority . $baseDirectory . $location;
	}

	// ------------------------------------------------------------------
	// Error output
	// ------------------------------------------------------------------

	/**
	 * Sends a plain-text error and ends the script.
	 */
	private function terminateWithError(int $httpStatusCode, string $message): void {
		http_response_code($httpStatusCode);
		header('Content-Type: text/plain; charset=UTF-8');
		echo $message;
		exit;
	}
}
