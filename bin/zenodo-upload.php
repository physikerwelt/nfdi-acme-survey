#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Upload one survey run to Zenodo as a draft dataset. Publishing is done by
 * hand in the Zenodo web interface after review; the token only needs the
 * deposit:write scope. Set it without it reaching the shell history:
 *
 *   read -s ZENODO_TOKEN && export ZENODO_TOKEN
 *   php bin/zenodo-upload.php --run=data/<date> [--production] [--new-version-of=RECORD]
 *
 *   --run             directory written by bin/survey.php
 *   --production      use zenodo.org instead of sandbox.zenodo.org
 *   --new-version-of  record id of the previous run, to add a version
 *
 * Metadata comes from zenodo/metadata.json (InvenioRDM format, including the
 * funding) and zenodo/description.html; date, version and the run's totals
 * are added. The code is not uploaded: the record links its Software
 * Heritage ID (swh:1:rev:<commit> of the run's code_commit), so that commit
 * must be pushed and archived on Software Heritage.
 */

namespace NfdiAcmeSurvey;

require __DIR__ . '/../src/autoload.php';

/** First version: expect problems; review the host lists before relying on them. */
const VERSION = 'alpha 01';

$options = getopt('', ['run:', 'production', 'new-version-of:']);
$root = dirname(__DIR__);
// The token comes from the environment or, on macOS, from the Keychain item
// "zenodo-deposit" (security add-generic-password -s zenodo-deposit -a "$USER" -w).
$token = getenv('ZENODO_TOKEN') ?: trim((string)shell_exec('security find-generic-password -s zenodo-deposit -w 2>/dev/null'));
if (!$token || !isset($options['run'])) {
	fwrite(STDERR, "usage: read -s ZENODO_TOKEN && export ZENODO_TOKEN; php bin/zenodo-upload.php --run=data/<date> [--production] [--new-version-of=RECORD]\n");
	exit(2);
}
$runDir = rtrim($options['run'], '/');
$run = json_decode((string)file_get_contents("$runDir/run.json"), true, 512, JSON_THROW_ON_ERROR);
$date = substr($run['started'], 0, 10);

$metadata = json_decode((string)file_get_contents("$root/zenodo/metadata.json"), true, 512, JSON_THROW_ON_ERROR);
$metadata['publication_date'] = $date;
$metadata['version'] = VERSION . " ($date)";
$t = $run['totals'];
$metadata['description'] = (string)file_get_contents("$root/zenodo/description.html") . sprintf(
	'<p>Run of %s: %d consortia, %d hostnames, %d reachable over TLS. Certificates by CA: %s. '
	. '%d consortia use only Let&#39;s Encrypt on their own domains.</p>',
	$date,
	$t['consortia'],
	$t['hostnames'],
	$t['reachable'],
	implode(', ', array_map(static fn ($ca, $n) => htmlspecialchars("$ca $n"), array_keys($t['by_ca']), $t['by_ca'])),
	$t['consortia_only_lets_encrypt'],
);

$commit = $run['code_commit'] ?? '';
exec('git -C ' . escapeshellarg($root) . ' branch -r --contains ' . escapeshellarg($commit) . ' 2>/dev/null', $remotes);
if (!preg_match('/^[0-9a-f]{40}$/', $commit) || !$remotes) {
	fwrite(STDERR, "code_commit in run.json must be a pushed commit, got '$commit'\n");
	exit(1);
}
$metadata['related_identifiers'][] = [
	'identifier' => "swh:1:rev:$commit",
	'scheme' => 'swh',
	'relation_type' => ['id' => 'iscompiledby'],
	'resource_type' => ['id' => 'software'],
];

$api = isset($options['production']) ? Zenodo::PRODUCTION : Zenodo::SANDBOX;
$zenodo = new Zenodo(new Http(sys_get_temp_dir() . '/nfdi-acme-survey-zenodo'), $api, $token);
try {
	$url = $zenodo->draft($metadata, glob("$runDir/*.{csv,json}", GLOB_BRACE), $options['new-version-of'] ?? null);
} catch (\RuntimeException $e) {
	fwrite(STDERR, "Zenodo: {$e->getMessage()}\n");
	exit(1);
}
echo "draft ready for review, publish it in the web interface: $url\n";
