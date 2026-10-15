<?php

declare(strict_types=1);

namespace NfdiAcmeSurvey;

use RuntimeException;

/**
 * Creates a Zenodo draft through the InvenioRDM REST API: a new record, or a
 * new version of an existing one. It never publishes: that is done by hand
 * in the Zenodo web interface, so the token needs only deposit:write.
 */
final class Zenodo
{
	public const SANDBOX = 'https://sandbox.zenodo.org/api';
	public const PRODUCTION = 'https://zenodo.org/api';

	/** Without it, Zenodo answers in the legacy format. */
	private const ACCEPT = 'application/vnd.inveniordm.v1+json';

	public function __construct(
		private readonly Http $http,
		private readonly string $api,
		private readonly string $token,
	) {
	}

	/**
	 * @param array<string, mixed> $metadata InvenioRDM record metadata
	 * @param list<string> $files
	 * @return string URL of the draft for review
	 */
	public function draft(array $metadata, array $files, ?string $previousRecord = null): string
	{
		$body = ['metadata' => $metadata, 'files' => ['enabled' => true], 'access' => ['record' => 'public', 'files' => 'public']];
		if ($previousRecord === null) {
			$draft = $this->call('POST', '/records', $body);
		} else {
			$latest = $this->call('GET', "/records/$previousRecord")['id'];
			$draft = $this->call('POST', "/records/$latest/versions");
		}
		$id = $draft['id'];
		try {
			if ($previousRecord !== null) {
				$this->call('PUT', "/records/$id/draft", $body);
			}
			foreach ($files as $file) {
				$key = basename($file);
				fwrite(STDERR, "uploading $key\n");
				$this->call('POST', "/records/$id/draft/files", [['key' => $key]]);
				$this->upload("/records/$id/draft/files/" . rawurlencode($key) . '/content', $file);
				$this->call('POST', "/records/$id/draft/files/" . rawurlencode($key) . '/commit');
			}
		} catch (RuntimeException $e) {
			// A broken draft blocks the next new version.
			$this->call('DELETE', "/records/$id/draft");
			fwrite(STDERR, "discarded the draft $id\n");
			throw $e;
		}
		return preg_replace('#/api$#', '', $this->api) . "/uploads/$id";
	}

	/**
	 * @return array<string, mixed>
	 */
	private function call(string $method, string $path, ?array $body = null): array
	{
		$headers = ['Authorization' => "Bearer $this->token", 'Accept' => self::ACCEPT];
		if ($body !== null) {
			$headers['Content-Type'] = 'application/json';
		}
		[$status, $response] = $this->http->request(
			$method,
			$this->api . $path,
			$headers,
			$body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
		);
		if ($status >= 300) {
			throw new RuntimeException("$method $path failed: HTTP $status $response");
		}
		return $response === '' ? [] : json_decode($response, true, 512, JSON_THROW_ON_ERROR);
	}

	private function upload(string $path, string $file): void
	{
		[$status, $response] = $this->http->request('PUT', $this->api . $path, [
			'Authorization' => "Bearer $this->token",
			'Content-Type' => 'application/octet-stream',
		], (string)file_get_contents($file), 600);
		if ($status >= 300) {
			throw new RuntimeException("PUT $path failed: HTTP $status $response");
		}
	}
}
