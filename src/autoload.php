<?php

declare(strict_types=1);

// No dependencies: a tiny PSR-4 style loader for the NfdiAcmeSurvey namespace.
spl_autoload_register(static function (string $class): void {
	$prefix = 'NfdiAcmeSurvey\\';
	if (str_starts_with($class, $prefix)) {
		require __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
	}
});
