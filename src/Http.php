<?php

declare(strict_types=1);

namespace NfdiAcmeSurvey;

use RuntimeException;

/**
 * Minimal HTTP client on top of ext-curl with retries and an on-disk cache,
 * so that a run can be repeated from the same raw responses.
 */
final class Http
{
	private readonly string $userAgent;

	/**
	 * Wikidata asks for a User-Agent with contact information; set
	 * SURVEY_CONTACT (a URL or e-mail address) to include one.
	 */
	public function __construct(
		private readonly string $cacheDir,
		private readonly int $retries = 4,
	) {
		$contact = getenv('SURVEY_CONTACT');
		$this->userAgent = 'nfdi-acme-survey/1.0' . ($contact ? " ($contact)" : ' (reproducible research script)');
		if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true)) {
			throw new RuntimeException("Cannot create cache directory $cacheDir");
		}
	}

	/**
	 * GET a URL and decode the JSON body. Responses are cached under a hash
	 * of the URL; pass $useCache = false to force a fresh request.
	 *
	 * @param array<string, string> $headers
	 */
	public function getJson(string $url, array $headers = [], bool $useCache = true, int $timeout = 120): mixed
	{
		$file = $this->cacheDir . '/' . sha1($url) . '.json';
		if ($useCache && is_file($file)) {
			return json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		}
		$lastError = '';
		for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
			[$status, $body] = $this->request('GET', $url, $headers, null, $timeout);
			if ($status === 200) {
				$data = json_decode($body, true);
				if (json_last_error() === JSON_ERROR_NONE) {
					file_put_contents($file, $body);
					return $data;
				}
				$lastError = 'invalid JSON';
			} else {
				$lastError = "HTTP $status";
			}
			// crt.sh fails intermittently under load; Wikidata answers 429
			// when queried too fast and wants a longer pause.
			sleep(($status === 429 ? 30 : 5) * $attempt);
		}
		throw new RuntimeException("GET $url failed after {$this->retries} attempts: $lastError");
	}

	/**
	 * @param array<string, string> $headers
	 * @return array{0: int, 1: string}
	 */
	public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 120): array
	{
		$ch = curl_init($url);
		$lines = ['User-Agent: ' . $this->userAgent];
		foreach ($headers as $name => $value) {
			$lines[] = "$name: $value";
		}
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_HTTPHEADER => $lines,
		]);
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$response = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		return [$status, is_string($response) ? $response : ''];
	}
}
