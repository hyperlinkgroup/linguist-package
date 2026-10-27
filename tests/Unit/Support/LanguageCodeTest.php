<?php

use Hyperlinkgroup\Linguist\Support\LanguageCode;

test('accepts valid language codes', function (string $code) {
	expect(LanguageCode::isValid($code))->toBeTrue();
})->with(['de', 'EN', 'pt-BR', 'zh_Hant', 'es-419', 'deu']);

test('rejects invalid language codes', function (string $code) {
	expect(LanguageCode::isValid($code))->toBeFalse();
})->with(['../x', '', 'd', 'de/..', 'de.json', '..', 'de-', 'de/linguist', ' de', "de\n", 'app_v']);

test('normalizes codes that differ only in case or separator', function () {
	expect(LanguageCode::normalize('pt_BR'))->toBe('PT-BR')
		->and(LanguageCode::normalize('pt-br'))->toBe('PT-BR')
		->and(LanguageCode::normalize('DE'))->toBe('DE');
});
