#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Survey which certificate authorities the NFDI consortia use.
 *
 * Reads organisations.json (see bin/fetch-organisations.php).
 *
 * Usage: php bin/survey.php [--out=DIR] [--no-cache]
 *   --out       output directory (default: data/<today>)
 *   --no-cache  ignore cached crt.sh responses
 */

namespace NfdiAcmeSurvey;

require __DIR__ . '/../src/autoload.php';

$options = getopt('', ['out:', 'no-cache']);
$root = dirname(__DIR__);
$today = gmdate('Y-m-d');
$out = $options['out'] ?? "$root/data/$today";
$cacheDir = "$root/cache/$today";
if (isset($options['no-cache'])) {
	array_map('unlink', glob("$cacheDir/*.json") ?: []);
}

$log = static function (string $line): void {
	fwrite(STDERR, gmdate('H:i:s') . " $line\n");
};

$http = new Http($cacheDir);
$survey = new Survey(new CertificateTransparency($http), new TlsProbe(), $log);
$organisations = json_decode((string)file_get_contents("$root/organisations.json"), true, 512, JSON_THROW_ON_ERROR);

// Reviewed service hosts outside the own domains (bin/discover-services.php).
$services = [];
if (is_file("$root/services.json")) {
	foreach (json_decode((string)file_get_contents("$root/services.json"), true, 512, JSON_THROW_ON_ERROR)['services'] as $qid => $entry) {
		$services[$qid] = array_column($entry['hosts'], 'host');
	}
}

$started = gmdate('c');
$result = $survey->run($organisations['organisations'], $services);
$run = [
	'started' => $started,
	'finished' => gmdate('c'),
	'tool' => 'nfdi-acme-survey',
	// Code version, cited on Zenodo by its Software Heritage ID (SWHID).
	'code_commit' => trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD')),
	'php' => PHP_VERSION,
	'openssl' => OPENSSL_VERSION_TEXT,
	'sources' => [
		'organisations' => 'organisations.json, fetched ' . $organisations['fetched'] . ' from ' . $organisations['source'],
		'hostnames' => 'crt.sh, certificates for %.<domain> excluding expired ones; plus reviewed service hosts from services.json',
		'issuers' => 'live TLS handshake on port 443 with SNI, certificate not verified',
	],
];
foreach ((new Report($out))->write($result['consortia'], $result['hosts'], $run) as $file) {
	$log("wrote $file");
}
