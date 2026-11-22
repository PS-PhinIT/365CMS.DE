<?php
/**
 * Statische Barrierefreiheitsregeln für ein HTML-Dokument (PR #5, Suite `community-a11y`): die Regeln von axe-core, die sich ohne Browser aus dem
 * Dokumentbaum entscheiden lassen (Sprache, Titel, Überschriften, Landmarken, Namen von Bedienelementen, Beschriftungen, ID-Verweise, ARIA-Rollen
 * und -Attribute, Listen, Tabellen, Statusmeldungen, Name enthält sichtbaren Text). Farbkontrast, Fokusreihenfolge und Zielgröße prüft die Suite
 * mit axe-core im Browser (optional); diese Regeln laufen immer und sind der Ersatz, wenn kein Browser da ist.
 *
 * `A11yRules::check($html)` liefert die Verstöße als Zeilen `<regel>: <Ort>`; leer = keine. Die Regelnamen sind die von axe-core.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

final class A11yRules
{
    /** Gültige ARIA-Rollen (WAI-ARIA 1.2 und die nötigen Grafik- und DPUB-Rollen). */
    private const ROLES = [
        'alert', 'alertdialog', 'application', 'article', 'banner', 'button', 'cell', 'checkbox', 'columnheader', 'combobox', 'complementary',
        'contentinfo', 'definition', 'dialog', 'directory', 'document', 'feed', 'figure', 'form', 'grid', 'gridcell', 'group', 'heading', 'img',
        'link', 'list', 'listbox', 'listitem', 'log', 'main', 'marquee', 'math', 'menu', 'menubar', 'menuitem', 'menuitemcheckbox',
        'menuitemradio', 'navigation', 'none', 'note', 'option', 'presentation', 'progressbar', 'radio', 'radiogroup', 'region', 'row',
        'rowgroup', 'rowheader', 'scrollbar', 'search', 'searchbox', 'separator', 'slider', 'spinbutton', 'status', 'switch', 'tab', 'table',
        'tablist', 'tabpanel', 'term', 'textbox', 'timer', 'toolbar', 'tooltip', 'tree', 'treegrid', 'treeitem',
    ];

    /** Gültige `aria-*`-Attribute (WAI-ARIA 1.2). */
    private const ARIA = [
        'activedescendant', 'atomic', 'autocomplete', 'busy', 'checked', 'colcount', 'colindex', 'colspan', 'controls', 'current', 'describedby',
        'description', 'details', 'disabled', 'dropeffect', 'errormessage', 'expanded', 'flowto', 'grabbed', 'haspopup', 'hidden', 'invalid',
        'keyshortcuts', 'label', 'labelledby', 'level', 'live', 'modal', 'multiline', 'multiselectable', 'orientation', 'owns', 'placeholder',
        'posinset', 'pressed', 'readonly', 'relevant', 'required', 'roledescription', 'rowcount', 'rowindex', 'rowspan', 'selected', 'setsize',
        'sort', 'valuemax', 'valuemin', 'valuenow', 'valuetext',
    ];

    /** Attribute mit Verweisen auf IDs (eine ID oder durch Leerzeichen getrennte Liste). */
    private const IDREFS = ['aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns', 'aria-flowto', 'aria-details', 'aria-errormessage', 'aria-activedescendant'];

    /** Elemente, die ohne Zutun den Fokus bekommen. */
    private const FOCUSABLE = ['a[@href]', 'button', 'input[not(@type="hidden")]', 'select', 'textarea', 'summary', '*[@tabindex]'];

    /**
     * @param bool $page true: ganzes Dokument (Sprache, Titel, Überschrift, Landmarken, Sprunglink); false: Ausschnitt (nur Elementregeln)
     * @return list<string>
     */
    public static function check(string $html, bool $page = true): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new DOMXPath($doc);
        $v = [];
        if ($page) {
            self::documentRules($doc, $xp, $v);
        }
        self::headings($xp, $v, $page);
        self::names($xp, $v);
        self::ids($xp, $v);
        self::aria($xp, $v);
        self::structure($xp, $v);
        self::liveRegions($xp, $v);

        return $v;
    }

    // ── Dokument ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $v */
    private static function documentRules(DOMDocument $doc, DOMXPath $xp, array &$v): void
    {
        $lang = trim((string) $xp->evaluate('string(/html/@lang)'));
        if ($lang === '') {
            $v[] = 'html-has-lang: <html> ohne lang';
        } elseif (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $lang) !== 1) {
            $v[] = 'html-lang-valid: lang="' . $lang . '"';
        }
        if (trim((string) $xp->evaluate('string(/html/head/title)')) === '') {
            $v[] = 'document-title: <title> fehlt oder ist leer';
        }
        $h1 = (int) $xp->evaluate('count(//h1[not(ancestor::*[@hidden or @aria-hidden="true"])])');
        if ($h1 !== 1) {
            $v[] = 'page-has-heading-one: ' . $h1 . ' Überschriften der Ebene 1 (genau eine erwartet)';
        }
        $mains = (int) $xp->evaluate('count(//main | //*[@role="main"])');
        if ($mains !== 1) {
            $v[] = 'landmark-one-main: ' . $mains . ' Hauptbereiche (genau einer erwartet)';
        }
        // Sprunglink: führt zu einem vorhandenen Ziel, das der Hauptbereich ist oder in ihm liegt, und steht vor dem übrigen Inhalt
        $skip = $xp->query('//a[contains(concat(" ", normalize-space(@class), " "), " skip-link ")]');
        if ($skip === false || $skip->length === 0) {
            $v[] = 'skip-link: kein Sprunglink zum Inhalt';
        } else {
            $href = (string) $skip->item(0)?->getAttribute('href');
            $target = str_starts_with($href, '#') ? $doc->getElementById(substr($href, 1)) : null;
            if ($target === null) {
                $v[] = 'skip-link: Ziel ' . $href . ' fehlt';
            } elseif ($target->nodeName !== 'main' && $target->getAttribute('role') !== 'main' && !self::within($target, 'main')) {
                $v[] = 'skip-link: Ziel ' . $href . ' liegt nicht im Hauptbereich';
            }
            $first = $xp->query('//body//*[self::a or self::button or self::input or self::select or self::textarea][1]');
            if ($first !== false && $first->length > 0 && $first->item(0) !== $skip->item(0)) {
                $v[] = 'skip-link: nicht das erste bedienbare Element';
            }
        }
        // region: Inhalt außerhalb von Landmarken (Sprunglink ausgenommen)
        $stray = $xp->query('/html/body/*[not(self::main or self::nav or self::header or self::footer or self::aside or self::script or self::style or self::template or self::link or self::noscript or @role or self::a[contains(concat(" ", normalize-space(@class), " "), " skip-link ")])]');
        foreach ($stray === false ? [] : $stray as $node) {
            if (trim($node->textContent) !== '') {
                $v[] = 'region: Inhalt außerhalb von Landmarken: <' . $node->nodeName . '>';
            }
        }
        // Navigationen: mehr als eine braucht einen Namen, die Namen sind verschieden
        $navs = $xp->query('//nav | //*[@role="navigation"]');
        $names = [];
        foreach ($navs === false ? [] : $navs as $nav) {
            // Der Name einer Landmarke kommt nur aus aria-labelledby/aria-label/title, nie aus dem Inhalt
            $name = $nav->hasAttribute('aria-labelledby') || $nav->hasAttribute('aria-label') ? self::accessibleName($nav, $xp) : trim($nav->getAttribute('title'));
            if ($navs->length > 1 && $name === '') {
                $v[] = 'landmark-unique: <nav> ohne Namen bei mehreren Navigationen';
            }
            if ($name !== '' && isset($names[$name])) {
                $v[] = 'landmark-unique: zwei Navigationen heißen "' . $name . '"';
            }
            $names[$name] = true;
        }
        // Suche: Formular mit role=search hat ein Eingabefeld
        foreach ($xp->query('//form[@role="search"]') ?: [] as $form) {
            if ($xp->query('.//input[@type="search" or @type="text"]', $form)?->length === 0) {
                $v[] = 'landmark-search: Suchformular ohne Eingabefeld';
            }
        }
    }

    // ── Überschriften ───────────────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $v */
    private static function headings(DOMXPath $xp, array &$v, bool $page): void
    {
        $last = 0;
        foreach (self::nodes($xp, '//h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //*[@role="heading"]') as $heading) {
            /** @var DOMElement $heading */
            $level = $heading->hasAttribute('aria-level') ? (int) $heading->getAttribute('aria-level') : (int) substr($heading->nodeName, 1);
            $label = trim(self::label($heading));
            if (self::contentText($heading, $xp) === '' && $label === '') {
                $v[] = 'empty-heading: <' . $heading->nodeName . '> ohne Text';
            }
            if ($page && $last === 0 && $level !== 1) {
                $v[] = 'page-has-heading-one: die erste Überschrift ist <h' . $level . '>';
            }
            if ($last > 0 && $level > $last + 1) {
                $v[] = 'heading-order: <h' . $last . '> gefolgt von <h' . $level . '> ("' . mb_substr(self::contentText($heading, $xp), 0, 40) . '")';
            }
            $last = $level;
        }
    }

    // ── Namen ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $v */
    private static function names(DOMXPath $xp, array &$v): void
    {
        $where = static fn (DOMElement $el): string => '<' . $el->nodeName . ($el->hasAttribute('id') ? ' id="' . $el->getAttribute('id') . '"' : '') . ($el->hasAttribute('name') ? ' name="' . $el->getAttribute('name') . '"' : '') . '>';
        foreach (self::nodes($xp, '//img') as $img) {
            /** @var DOMElement $img */
            if (!$img->hasAttribute('alt') && !$img->hasAttribute('aria-label') && !$img->hasAttribute('aria-labelledby') && $img->getAttribute('role') !== 'presentation' && $img->getAttribute('role') !== 'none') {
                $v[] = 'image-alt: Bild ohne alt (' . $img->getAttribute('src') . ')';
            }
        }
        foreach (self::nodes($xp, '//svg[@role="img"]') as $svg) {
            /** @var DOMElement $svg */
            if (self::accessibleName($svg, $xp) === '') {
                $v[] = 'svg-img-alt: Grafik mit role="img" ohne Namen';
            }
        }
        foreach (self::nodes($xp, '//input[not(@type) or @type="text" or @type="search" or @type="email" or @type="url" or @type="tel" or @type="password" or @type="number" or @type="date" or @type="checkbox" or @type="radio" or @type="file"] | //select | //textarea | //*[@role="textbox" or @role="combobox" or @role="listbox" or @role="searchbox" or @role="checkbox" or @role="radio" or @role="switch"]') as $field) {
            /** @var DOMElement $field */
            if (self::accessibleName($field, $xp) === '') {
                $v[] = ($field->nodeName === 'select' ? 'select-name' : 'label') . ': Feld ohne Beschriftung ' . $where($field);
            }
            $id = $field->getAttribute('id');
            if ($id !== '' && (int) $xp->evaluate('count(//label[@for="' . $id . '"])') > 1) {
                $v[] = 'form-field-multiple-labels: mehrere Beschriftungen für ' . $where($field);
            }
        }
        foreach (self::nodes($xp, '//input[@type="submit" or @type="button" or @type="reset" or @type="image"] | //button | //*[@role="button"] | //summary') as $button) {
            /** @var DOMElement $button */
            if (self::accessibleName($button, $xp) === '') {
                $v[] = 'button-name: Schaltfläche ohne Namen ' . $where($button);
            }
        }
        foreach (self::nodes($xp, '//a[@href] | //*[@role="link"]') as $link) {
            /** @var DOMElement $link */
            if (self::accessibleName($link, $xp) === '') {
                $v[] = 'link-name: Link ohne Namen (' . $link->getAttribute('href') . ')';
            }
        }
        // Name enthält den sichtbaren Text (WCAG 2.5.3): ein aria-label, das den sichtbaren Text nicht enthält, trennt Sprachsteuerung und Anzeige
        foreach (self::nodes($xp, '//a[@href][@aria-label] | //button[@aria-label] | //*[@role="button"][@aria-label]') as $control) {
            /** @var DOMElement $control */
            $visible = self::tokens(self::contentText($control, $xp, false));
            if ($visible !== '' && !str_contains(self::tokens($control->getAttribute('aria-label')), $visible)) {
                $v[] = 'label-content-name-mismatch: aria-label "' . $control->getAttribute('aria-label') . '" enthält nicht den sichtbaren Text "' . trim(self::contentText($control, $xp, false)) . '"';
            }
        }
        // Gruppen von Optionsfeldern brauchen eine Gruppenbeschriftung
        $radios = [];
        foreach (self::nodes($xp, '//input[@type="radio"]') as $radio) {
            /** @var DOMElement $radio */
            $radios[$radio->getAttribute('name')][] = $radio;
        }
        foreach ($radios as $name => $group) {
            $first = $group[0];
            $labelled = false;
            for ($node = $first->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
                if (($node->nodeName === 'fieldset' && $xp->query('./legend', $node)?->length > 0) || (in_array($node->getAttribute('role'), ['radiogroup', 'group'], true) && self::accessibleName($node, $xp) !== '')) {
                    $labelled = true;
                    break;
                }
            }
            if (!$labelled && count($group) > 1) {
                $v[] = 'radiogroup: Optionsfelder "' . $name . '" ohne Gruppenbeschriftung';
            }
        }
        foreach (self::nodes($xp, '//iframe') as $frame) {
            /** @var DOMElement $frame */
            if (self::accessibleName($frame, $xp) === '') {
                $v[] = 'frame-title: <iframe> ohne Titel';
            }
        }
    }

    // ── IDs und Verweise ────────────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $v */
    private static function ids(DOMXPath $xp, array &$v): void
    {
        $seen = [];
        foreach ($xp->query('//*[@id]') ?: [] as $el) {
            /** @var DOMElement $el */
            $id = $el->getAttribute('id');
            if (isset($seen[$id])) {
                $v[] = 'duplicate-id: id="' . $id . '" mehrfach';
            }
            $seen[$id] = true;
        }
        foreach ($xp->query('//*[@aria-labelledby or @aria-describedby or @aria-controls or @aria-owns or @aria-flowto or @aria-details or @aria-errormessage or @aria-activedescendant or self::label[@for]]') ?: [] as $el) {
            /** @var DOMElement $el */
            $attrs = self::IDREFS;
            if ($el->nodeName === 'label') {
                $attrs[] = 'for';
            }
            foreach ($attrs as $attr) {
                if (!$el->hasAttribute($attr)) {
                    continue;
                }
                $refs = preg_split('/\s+/', trim($el->getAttribute($attr))) ?: [];
                foreach ($refs as $ref) {
                    if ($ref === '' || !isset($seen[$ref])) {
                        // aria-controls darf auf Ziele zeigen, die erst das Skript anlegt; die anderen nicht
                        if ($attr !== 'aria-controls') {
                            $v[] = 'aria-valid-attr-value: ' . $attr . '="' . $ref . '" zeigt auf keine ID';
                        }
                    }
                }
            }
        }
        foreach ($xp->query('//a[starts-with(@href, "#") and string-length(@href) > 1]') ?: [] as $anchor) {
            /** @var DOMElement $anchor */
            if (!isset($seen[substr($anchor->getAttribute('href'), 1)])) {
                $v[] = 'anchor: Sprungziel ' . $anchor->getAttribute('href') . ' fehlt';
            }
        }
    }

    // ── ARIA ────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $v */
    private static function aria(DOMXPath $xp, array &$v): void
    {
        foreach (self::nodes($xp, '//*[@role]') as $el) {
            /** @var DOMElement $el */
            foreach (preg_split('/\s+/', trim($el->getAttribute('role'))) ?: [] as $role) {
                if ($role !== '' && !in_array($role, self::ROLES, true)) {
                    $v[] = 'aria-roles: role="' . $role . '" ist keine gültige Rolle (<' . $el->nodeName . '>)';
                }
            }
        }
        foreach (self::nodes($xp, '//*') as $el) {
            /** @var DOMElement $el */
            foreach ($el->attributes ?? [] as $attr) {
                if (str_starts_with($attr->name, 'aria-') && !in_array(substr($attr->name, 5), self::ARIA, true)) {
                    $v[] = 'aria-valid-attr: ' . $attr->name . ' ist kein ARIA-Attribut (<' . $el->nodeName . '>)';
                }
            }
        }
        // Werte der Schalter-Attribute
        foreach (['aria-pressed' => ['true', 'false', 'mixed'], 'aria-expanded' => ['true', 'false'], 'aria-hidden' => ['true', 'false'], 'aria-current' => ['page', 'step', 'location', 'date', 'time', 'true', 'false'], 'aria-live' => ['off', 'polite', 'assertive'], 'aria-required' => ['true', 'false'], 'aria-invalid' => ['true', 'false', 'grammar', 'spelling'], 'aria-haspopup' => ['true', 'false', 'menu', 'listbox', 'tree', 'grid', 'dialog'], 'aria-modal' => ['true', 'false']] as $attr => $allowed) {
            foreach (self::nodes($xp, '//*[@' . $attr . ']') as $el) {
                /** @var DOMElement $el */
                if (!in_array($el->getAttribute($attr), $allowed, true)) {
                    $v[] = 'aria-valid-attr-value: ' . $attr . '="' . $el->getAttribute($attr) . '" (<' . $el->nodeName . '>)';
                }
            }
        }
        // aria-hidden auf Fokussierbarem
        foreach (self::nodes($xp, '//*[@aria-hidden="true"]//' . implode(' | //*[@aria-hidden="true"]//', self::FOCUSABLE) . ' | //*[@aria-hidden="true"][self::a[@href] or self::button or self::input or self::select or self::textarea]') as $el) {
            /** @var DOMElement $el */
            if ($el->getAttribute('tabindex') !== '-1' && !$el->hasAttribute('disabled')) {
                $v[] = 'aria-hidden-focus: fokussierbares <' . $el->nodeName . '> in aria-hidden';
            }
        }
        foreach (self::nodes($xp, '//*[@tabindex]') as $el) {
            /** @var DOMElement $el */
            if ((int) $el->getAttribute('tabindex') > 0) {
                $v[] = 'tabindex: positiver Wert (<' . $el->nodeName . '>)';
            }
        }
        // Umschalter brauchen einen Zustand: Schaltflächen mit aria-pressed haben einen Namen, der sich nicht mit dem Zustand ändert, oder umgekehrt
        foreach (self::nodes($xp, '//*[@accesskey]') as $el) {
            $v[] = 'accesskey: accesskey erzeugt Tastenkonflikte (<' . $el->nodeName . '>)';
        }
    }

    // ── Struktur ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $v */
    private static function structure(DOMXPath $xp, array &$v): void
    {
        foreach (self::nodes($xp, '//li[not(parent::ul or parent::ol or parent::menu or parent::*[@role="list" or @role="menu" or @role="menubar" or @role="tablist" or @role="listbox" or @role="tree" or @role="group"])]') as $el) {
            $v[] = 'listitem: <li> außerhalb einer Liste';
        }
        foreach (self::nodes($xp, '//ul/*[not(self::li or self::script or self::template)] | //ol/*[not(self::li or self::script or self::template)]') as $el) {
            $v[] = 'list: <' . $el->parentNode?->nodeName . '> enthält <' . $el->nodeName . '> statt <li>';
        }
        foreach (self::nodes($xp, '//dt[not(parent::dl or parent::div[parent::dl])] | //dd[not(parent::dl or parent::div[parent::dl])]') as $el) {
            $v[] = 'definition-list: <' . $el->nodeName . '> außerhalb von <dl>';
        }
        foreach (self::nodes($xp, '//dl/*[not(self::dt or self::dd or self::div or self::script or self::template)]') as $el) {
            $v[] = 'definition-list: <dl> enthält <' . $el->nodeName . '>';
        }
        foreach (self::nodes($xp, '//table[not(@role="presentation" or @role="none")]') as $table) {
            /** @var DOMElement $table */
            if ($xp->query('.//th', $table)?->length === 0) {
                $v[] = 'table: Datentabelle ohne Kopfzellen';
            }
            foreach (self::nodes($xp, './/th[not(@scope) and not(@id)]', $table) as $th) {
                $v[] = 'th-has-data-cells: <th> ohne scope ("' . trim($th->textContent) . '")';
            }
        }
        foreach (self::nodes($xp, '//a[@target="_blank"]') as $link) {
            /** @var DOMElement $link */
            if (!preg_match('/\bnoopener\b/', $link->getAttribute('rel'))) {
                $v[] = 'link-target: target="_blank" ohne rel="noopener" (' . $link->getAttribute('href') . ')';
            }
        }
        foreach (self::nodes($xp, '//meta[@name="viewport"]') as $meta) {
            /** @var DOMElement $meta */
            if (preg_match('/user-scalable\s*=\s*(no|0)|maximum-scale\s*=\s*(0?\.\d|1(\.0)?)\b/i', $meta->getAttribute('content')) === 1) {
                $v[] = 'meta-viewport: Vergrößern gesperrt';
            }
        }
        foreach (self::nodes($xp, '//*[self::marquee or self::blink]') as $el) {
            $v[] = 'marquee: <' . $el->nodeName . '> ist nicht erlaubt';
        }
        foreach (self::nodes($xp, '//meta[@http-equiv="refresh"]') as $el) {
            $v[] = 'meta-refresh: automatische Weiterleitung per meta';
        }
    }

    // ── Statusmeldungen ─────────────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $v */
    private static function liveRegions(DOMXPath $xp, array &$v): void
    {
        // Meldungen der Seite (Hinweise und Fehler) sind Live-Regionen, damit Screenreader sie ansagen
        foreach ($xp->query('//*[contains(@class, "cms-community-flash") or contains(@class, "cms-community-alert") or contains(@class, "alert-danger") or contains(@class, "alert-info") or contains(@class, "alert-success")]') ?: [] as $el) {
            /** @var DOMElement $el */
            if ($el->hasAttribute('hidden') && trim($el->textContent) === '') {
                continue; // leerer Behälter, den das Skript füllt (siehe community.js: setzt role beim Anzeigen)
            }
            if (!in_array($el->getAttribute('role'), ['alert', 'status'], true) && !$el->hasAttribute('aria-live')) {
                $v[] = 'aria-live: Meldung ohne role="status"/"alert" ("' . mb_substr(trim(preg_replace('/\s+/', ' ', $el->textContent) ?? ''), 0, 40) . '")';
            }
        }
        // Vorschaubereiche, die das Skript füllt, sind Live-Regionen
        foreach ($xp->query('//*[@id="cc-preview"]') ?: [] as $el) {
            /** @var DOMElement $el */
            if ($el->getAttribute('aria-live') === '' && $el->getAttribute('role') === '') {
                $v[] = 'aria-live: Vorschaubereich ohne aria-live';
            }
        }
    }

    // ── Hilfsfunktionen ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Knoten eines XPath-Ausdrucks, die der Seite angehören: nicht in `hidden`, nicht in einem geschlossenen `<dialog>`, nicht in `<template>`
     * (dort liest kein Hilfsmittel, axe-core übergeht sie ebenso).
     *
     * @return list<DOMElement>
     */
    private static function nodes(DOMXPath $xp, string $expression, ?DOMNode $context = null): array
    {
        $found = $context === null ? $xp->query($expression) : $xp->query($expression, $context);
        $nodes = [];
        foreach ($found === false ? [] : $found as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            for ($parent = $node; $parent instanceof DOMElement; $parent = $parent->parentNode) {
                if ($parent->hasAttribute('hidden') || $parent->nodeName === 'template' || ($parent->nodeName === 'dialog' && !$parent->hasAttribute('open'))) {
                    continue 2;
                }
            }
            $nodes[] = $node;
        }

        return $nodes;
    }

    /** true, wenn ein Vorfahre das Element `$tag` ist. */
    private static function within(DOMNode $node, string $tag): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent->nodeName === $tag) {
                return true;
            }
        }

        return false;
    }

    /** Name der Beschriftung per `aria-label` bzw. `aria-labelledby` (leer, wenn keins da). */
    private static function label(DOMElement $el): string
    {
        return trim($el->getAttribute('aria-label'));
    }

    /**
     * Sichtbarer und für Hilfsmittel lesbarer Text eines Elements: Text, `alt` von Bildern, `<title>` von Grafiken; ausgeblendete Teile
     * (`hidden`, `aria-hidden="true"`) zählen nicht. `$withHidden`: false lässt auch visuell versteckten Text (`visually-hidden`) weg.
     */
    private static function contentText(DOMNode $node, DOMXPath $xp, bool $withHidden = true): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text .= $child->nodeValue;
            } elseif ($child instanceof DOMElement) {
                if ($child->hasAttribute('hidden') || $child->getAttribute('aria-hidden') === 'true' || in_array($child->nodeName, ['script', 'style', 'template'], true)) {
                    continue;
                }
                if (!$withHidden && preg_match('/\b(visually-hidden|sr-only)\b/', $child->getAttribute('class')) === 1) {
                    continue;
                }
                if ($child->nodeName === 'img') {
                    $text .= ' ' . $child->getAttribute('alt') . ' ';
                } elseif ($child->nodeName === 'svg') {
                    $text .= ' ' . (string) $xp->evaluate('string(./title)', $child) . ' ';
                } elseif ($child->hasAttribute('aria-label') && $withHidden) {
                    $text .= ' ' . $child->getAttribute('aria-label') . ' ';
                } else {
                    $text .= ' ' . self::contentText($child, $xp, $withHidden) . ' ';
                }
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Berechneter Name (vereinfacht nach „Accessible Name Computation“): labelledby, aria-label, Beschriftung, Inhalt, Titel. */
    private static function accessibleName(DOMElement $el, DOMXPath $xp): string
    {
        if ($el->hasAttribute('aria-labelledby')) {
            $name = '';
            foreach (preg_split('/\s+/', trim($el->getAttribute('aria-labelledby'))) ?: [] as $id) {
                $target = $el->ownerDocument?->getElementById($id);
                if ($target !== null) {
                    $name .= ' ' . self::contentText($target, $xp);
                }
            }
            if (trim($name) !== '') {
                return trim($name);
            }
        }
        if (trim($el->getAttribute('aria-label')) !== '') {
            return trim($el->getAttribute('aria-label'));
        }
        $tag = $el->nodeName;
        $type = strtolower($el->getAttribute('type'));
        if (in_array($tag, ['input', 'select', 'textarea'], true)) {
            if (in_array($type, ['submit', 'button', 'reset'], true)) {
                return trim($el->getAttribute('value')) !== '' ? trim($el->getAttribute('value')) : ($type === 'submit' || $type === 'reset' ? 'x' : '');
            }
            if ($type === 'image') {
                return trim($el->getAttribute('alt'));
            }
            $id = $el->getAttribute('id');
            if ($id !== '') {
                foreach ($xp->query('//label[@for="' . $id . '"]') ?: [] as $label) {
                    $text = self::contentText($label, $xp);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
            for ($node = $el->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
                if ($node->nodeName === 'label') {
                    $text = self::contentText($node, $xp);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }

            return trim($el->getAttribute('title'));
        }
        if ($tag === 'iframe') {
            return trim($el->getAttribute('title'));
        }
        if ($tag === 'svg') {
            return trim((string) $xp->evaluate('string(./title)', $el)) ?: trim($el->getAttribute('title'));
        }
        $content = self::contentText($el, $xp);

        return $content !== '' ? $content : trim($el->getAttribute('title'));
    }

    /** Vergleichsform: nur Buchstaben und Ziffern, klein, durch ein Leerzeichen getrennt (Symbole und Emojis zählen nicht als Text). */
    private static function tokens(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }
}
