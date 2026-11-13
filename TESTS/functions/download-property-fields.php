<?php
/**
 * Download-Eigenschaftsfelder: Bezeichnungen sind reiner Text (kein Markup im Block-Menü des Editors), und der
 * Upload-Endpunkt nimmt neu angelegte Felder zurück, wenn kein Download entsteht.
 * Wird von TESTS/functions/run.php eingebunden.
 */

declare(strict_types=1);

use CMS\Services\Downloads\DownloadSettings;

T::run('Downloads: Bezeichnung neuer Eigenschaftsfelder ohne Markup', static function (): void {
    [$fields, $key] = DownloadSettings::appendPropertyField([], '<b>Lizenz</b>', 'text');
    T::same('lizenz', $key, 'Schlüssel aus dem bereinigten Text');
    T::same('Lizenz', $fields[0]['label'] ?? null, 'Bezeichnung ohne Tags');

    [$fields, $key] = DownloadSettings::appendPropertyField([], '<img src=x onerror=alert(1)>', 'text');
    T::same('', $key, 'reines Markup ergibt kein Feld');
    T::same([], $fields, 'Liste bleibt leer');
});

T::run('Downloads: gespeicherte Bezeichnungen werden beim Normalisieren bereinigt', static function (): void {
    $fields = DownloadSettings::normalizePropertyFields([
        ['key' => 'lizenz', 'label' => 'Lizenz<img src=x onerror=alert(1)>', 'type' => 'text'],
        ['key' => 'nur_markup', 'label' => '<script>alert(1)</script>', 'type' => 'text'],
    ]);
    T::same('Lizenz', $fields[0]['label'] ?? null, 'Bezeichnung ohne Tags');
    T::check(!str_contains((string) ($fields[1]['label'] ?? '<'), '<'), 'kein Tag in der Bezeichnung');
});

T::run('Downloads: Block-Menü maskiert die Bezeichnung, Upload-Fehler nimmt neue Felder zurück', static function (): void {
    $js = (string) file_get_contents(ABSPATH . 'assets/js/editor-blocks/download-block.js');
    T::check(!str_contains($js, "'Eigenschaft „' + field.label + '“ anzeigen'"), 'Bezeichnung nicht ungefiltert im Menütitel');

    $api = (string) file_get_contents(ABSPATH . 'admin/post-editor-api.php');
    $failure = strpos($api, "empty(\$uploadResult['success'])");
    $audit = strpos($api, 'Eigenschaftsfelder im Editor ergänzt');
    T::check($failure !== false && $audit !== false && $audit > $failure, 'Audit-Eintrag erst nach erfolgreichem Upload');
    T::check(str_contains(substr($api, (int) $failure, 600), '$addedPropertyKeys'), 'Fehlerpfad entfernt die neu angelegten Felder');
});
