<?php
/**
 * Markdown-Renderer der Community (PR #2b; Vertrag DESIGN-2b §4.7, Entwurf render §1.1).
 *
 * Markdown → HTML über das gescopte CommonMark-Bündel (`CMS/assets/commonmark/commonmark.php`, Namensraum `CMS\Vendor\…`)
 * mit eigenen Renderern, danach `PurifierService::purify($html, 'community')`. Dazu die Zählung im Syntaxbaum (`Stats`, Schnittstelle
 * für Schutz, Medien und Benachrichtigungen) und der Klartext für Suche und Hinweise.
 *
 * Rein: keine Datenbank, keine Rechte, keine Uhr, keine Sprache. Das HTML ist betrachterunabhängig; Zitat-Blöcke tragen nur die
 * Beitrags-ID (`data-quote-post`), die `RenderService::present()` je Betrachter auflöst.
 *
 * Ressourcengrenzen (Vertrag §4.7 Nr. 1 und 2): Vor jedem Parsen prüft `withinLimits()` Bytes, Zeilen, Begrenzer (einschließlich
 * Tag-Anfängen, `\`, `@` und `www.`) und den Speicher (`memoryNeed()` gegen `$headroom`). Nach dem Parsen, vor dem Rendern,
 * zählt ein Listener mit Priorität 100 Knoten und Tabellenzellen. Speichermangel und Zeitüberschreitung sind keine Throwables;
 * deshalb wird vor der teuren Arbeit geprüft. Ein Verstoß ergibt `ok = false` (`render_failed` beim Schreiben, Ausweichpfad bei der Anzeige).
 *
 * Regeln R1 bis R12 laut Entwurf render §1.1; Zitatgrenze `MAX_QUOTES` und Host-Regel `normalizeHost()` laut Vertrag §4.7 Nr. 6 und 7.
 * Nicht reentrant: ein verschachtelter Aufruf (z. B. aus der Anhangs-Closure) ergibt `render_failed`.
 *
 * Änderungen an Konfiguration, eigenen Renderern, Profil `community`, `HTML_DEFINITION_REVISION`, Bündel oder Marker-Regel
 * verlangen eine höhere `VERSION` (Fingerabdruck-Test in `TESTS/community-render`, Abschnitt F).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

use CMS\Logger;
use CMS\Services\Community\Service\QuoteService;
use CMS\Services\PurifierService;
use CMS\Vendor\League\CommonMark\Environment\Environment;
use CMS\Vendor\League\CommonMark\Event\DocumentParsedEvent;
use CMS\Vendor\League\CommonMark\Extension\Autolink\AutolinkExtension;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use CMS\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use CMS\Vendor\League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use CMS\Vendor\League\CommonMark\Extension\Table\TableCell;
use CMS\Vendor\League\CommonMark\Extension\Table\TableExtension;
use CMS\Vendor\League\CommonMark\Extension\TaskList\TaskListExtension;
use CMS\Vendor\League\CommonMark\Extension\TaskList\TaskListItemMarker;
use CMS\Vendor\League\CommonMark\MarkdownConverter;
use CMS\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CMS\Vendor\League\CommonMark\Node\Block\Document;
use CMS\Vendor\League\CommonMark\Node\Block\Paragraph;
use CMS\Vendor\League\CommonMark\Node\Inline\Newline;
use CMS\Vendor\League\CommonMark\Node\Inline\Text;
use CMS\Vendor\League\CommonMark\Node\Node;
use CMS\Vendor\League\CommonMark\Node\StringContainerInterface;
use CMS\Vendor\League\CommonMark\Parser\MarkdownParser;
use CMS\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CMS\Vendor\League\CommonMark\Renderer\HtmlRenderer;
use CMS\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CMS\Vendor\League\CommonMark\Util\HtmlElement;
use CMS\Vendor\League\CommonMark\Util\RegexHelper;
use CMS\Vendor\League\CommonMark\Util\Xml;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type Stats array{links: int, external_links: int, images: int, external_images: int, attachments: list<int>, hosts: list<string>, mentions: int, quotes: list<int>}
 */
final class ContentRenderer
{
    /** Stand der Darstellung; Wert für `rendered_version`. */
    public const VERSION = 1;
    /** Älteste Version, deren gespeichertes HTML noch ausgeliefert wird (bei Sicherheitskorrekturen mit VERSION anheben). */
    public const MIN_SERVE_VERSION = 1;
    /** Purifier-Profil. */
    public const PROFILE = 'community';

    /** Größte Eingabe in Bytes: 4 × Höchstwert von `post_max` (200.000 Zeichen). */
    public const MAX_BYTES = 800000;
    /** Größte Zahl der Zeilen (`substr_count("\n") + 1`). */
    public const MAX_LINES = 20000;
    /** Größte Zahl der Begrenzer (`DELIMITER_CHARS`, auch in Code), Tag-Anfänge (`<` vor Buchstabe, `/`, `!`, `?`) und `www.`. */
    public const MAX_DELIMITERS = 20000;
    /** Größte Zahl der Knoten im Syntaxbaum (nach dem Parsen, vor dem Rendern). */
    public const MAX_NODES = 25000;
    /** Größte Zahl der Tabellenzellen. */
    public const MAX_TABLE_CELLS = 5000;
    /** Höchstens so viele Zitat-Blöcke erhalten `data-quote-post`; weitere werden als „[…]“ ohne Quelltext ausgegeben. */
    public const MAX_QUOTES = 20;
    /** Obergrenze der Speicherschätzung `memoryNeed()` (64 MiB). */
    public const MEMORY_CEILING = 67108864;
    /** Höchstens so viele verschiedene `attachment:`-IDs werden je Text nachgeschlagen; weitere ergeben den Alternativtext. */
    public const MAX_ATTACHMENTS = 50;

    /** Sprache eines Codeblocks: erstes Wort des Info-Strings (klein geschrieben) → Klasse `language-<Wert>`. */
    public const LANGUAGES = [
        'php' => 'php',
        'js' => 'javascript', 'javascript' => 'javascript', 'jsx' => 'javascript',
        'ts' => 'typescript', 'typescript' => 'typescript',
        'html' => 'xml', 'xml' => 'xml',
        'css' => 'css',
        'sql' => 'sql',
        'bash' => 'bash', 'sh' => 'bash', 'shell' => 'bash', 'zsh' => 'bash',
        'powershell' => 'powershell', 'ps1' => 'powershell', 'pwsh' => 'powershell',
        'json' => 'json',
        'yaml' => 'yaml', 'yml' => 'yaml',
        'python' => 'python', 'py' => 'python',
        'csharp' => 'csharp', 'cs' => 'csharp', 'c#' => 'csharp',
        'diff' => 'diff',
        'ini' => 'ini',
        'text' => 'plaintext', 'txt' => 'plaintext', 'plaintext' => 'plaintext',
    ];

    /** Anhänge, die als `<img>` eingebettet werden. */
    public const IMAGE_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Text von `<summary>` (in DE und EN gleich; der Cache bleibt sprachfrei). */
    public const SPOILER_SUMMARY = 'Spoiler';

    /** CommonMark-Konfiguration (Pflichtschreibweise der Mutanten, render §6.5). */
    private const CONFIG = [
        'html_input' => 'escape',
        'allow_unsafe_links' => false,
        'max_nesting_level' => 20,
        'max_delimiters_per_line' => 1000,
        'renderer' => ['soft_break' => "<br />\n"],
        'autolink' => ['allowed_protocols' => ['https', 'http'], 'default_protocol' => 'https'],
        'table' => [
            'max_autocompleted_cells' => 1000,
            'alignment_attributes' => ['left' => ['class' => 'text-start'], 'center' => ['class' => 'text-center'], 'right' => ['class' => 'text-end']],
        ],
    ];

    /**
     * Zeichen, die als Begrenzer zählen (Vertrag §4.7 Nr. 1). Jeder Treffer eines Inline-Parsers belegt in
     * `InlineParserEngine::matchParsers()` etwa 1,1 KB; `\` (Escape-Parser) und `@` (E-Mail-Autolink) zählen deshalb mit
     * (Nachbesserung N1: `\\` × 100.000 belegte 216 MiB bei einer Schätzung von 53 MiB).
     */
    private const DELIMITER_CHARS = '[]*_~`&@\\';

    /** Anhangsziel `attachment:ID` mit ID 1 bis 4294967295. */
    private const ATTACHMENT_PATTERN = '/^attachment:([1-9][0-9]{0,9})$/';
    private const MAX_ATTACHMENT_ID = 4294967295;

    /** Erwähnung in Textknoten außerhalb von Links und Bildern (nur gezählt, DR15). */
    private const MENTION_PATTERN = '/(?<![\p{L}\p{N}_@.])@[A-Za-z0-9_][A-Za-z0-9_.-]{1,39}/u';

    private static ?self $instance = null;

    /** @var \Closure(string): ?string */
    private \Closure $purify;
    /** @var \Closure(list<int>): array<int|string, array<string, mixed>> */
    private \Closure $attachments;
    /** @var \Closure(int, bool): string */
    private \Closure $attachmentUrl;
    private ?string $siteHost;
    /** @var \Closure(): bool */
    private \Closure $available;
    /** @var \Closure(): ?int */
    private \Closure $headroom;

    private ?bool $isAvailable = null;
    private ?MarkdownParser $parser = null;
    private ?HtmlRenderer $htmlRenderer = null;
    private ?Environment $environment = null;
    /** Tiefe laufender Aufrufe (R12: nicht reentrant). */
    private int $depth = 0;

    /**
     * Zustand des laufenden Aufrufs für Listener und Renderer.
     *
     * @var array{lines: list<string>, options: array<string, mixed>, stats: Stats, rows: array<int, array<string, mixed>>, structure: bool}|array{}
     */
    private array $call = [];

    /**
     * Alle Parameter optional; der Konstruktor baut nichts und berührt weder Datenbank noch Purifier.
     *
     * @param (\Closure(string): ?string)|null $purify Standard: `PurifierService::getInstance()`, Profil `community`; `null`, wenn HTMLPurifier fehlt
     * @param (\Closure(list<int>): array<int|string, array<string, mixed>>)|null $attachments Zeilen `{id, post_id, user_id, mime_type, width, height, has_preview}`; Standard: keine
     * @param (\Closure(int, bool): string)|null $attachmentUrl Standard: `Urls::attachment($id, $preview)` (immer `de`, Cache sprachfrei)
     * @param string|null $siteHost Standard: Host aus `SITE_URL` über `normalizeHost()` (`www.` bleibt)
     * @param (\Closure(): bool)|null $available Standard: Bündel ladbar
     * @param (\Closure(): ?int)|null $headroom freie Bytes bis `memory_limit`, `null` = unbegrenzt; Standard aus `memory_limit` und `memory_get_usage()`
     */
    public function __construct(
        ?\Closure $purify = null,
        ?\Closure $attachments = null,
        ?\Closure $attachmentUrl = null,
        ?string $siteHost = null,
        ?\Closure $available = null,
        ?\Closure $headroom = null
    ) {
        $this->purify = $purify ?? static function (string $html): ?string {
            $purifier = PurifierService::getInstance();

            return $purifier->isAvailable() ? $purifier->purify($html, self::PROFILE) : null;
        };
        $this->attachments = $attachments ?? static fn (array $ids): array => [];
        $this->attachmentUrl = $attachmentUrl ?? static fn (int $id, bool $preview): string => Urls::attachment($id, $preview);
        $this->siteHost = $siteHost !== null ? self::normalizeHost($siteHost) : null;
        $this->available = $available ?? static fn (): bool => class_exists(MarkdownConverter::class, true);
        $this->headroom = $headroom ?? static function (): ?int {
            $limit = self::iniBytes((string) ini_get('memory_limit'));

            return $limit > 0 ? $limit - memory_get_usage() : null;
        };
    }

    /** Gemeinsame Instanz ohne Anhänge (für `analyze()` und `plainText()` ohne Datenbank). */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Ist das CommonMark-Bündel ladbar? (je Instanz gemerkt) */
    public function isAvailable(): bool
    {
        return $this->isAvailable ??= (bool) ($this->available)();
    }

    /**
     * Markdown → gereinigtes HTML samt Zählung.
     *
     * @param array{post_id?: ?int, uploader_id?: ?int, images?: bool} $options
     *   post_id:     Beitrag, dem verknüpfte Anhänge gehören (null bei neuem Beitrag)
     *   uploader_id: Mitglied, dessen noch unverknüpfte Uploads (post_id NULL) eingebettet werden dürfen
     *   images:      false → Bilder als Link bzw. Alternativtext
     * @return array{ok: bool, html: ?string, version: int, stats: Stats, error?: 'render_failed'}
     *   ok = false: Bündel fehlt, Ausnahme, Grenze überschritten, verschachtelter Aufruf (html null, version 0, leere Zählung)
     *   ok = true, html = null, version = 0: Purifier fehlt oder lieferte '' für nicht leeres HTML
     */
    public function render(string $markdown, array $options = []): array
    {
        $run = $this->run($markdown, $options, 'html');
        if ($run === null) {
            return ['ok' => false, 'html' => null, 'version' => 0, 'stats' => self::emptyStats(), 'error' => 'render_failed'];
        }

        return ['ok' => true, 'html' => $run['html'], 'version' => $run['html'] === null ? 0 : self::VERSION, 'stats' => $run['stats']];
    }

    /**
     * Nur Parsen und Zählen (kein HTML, kein Purifier). Dieselben Grenzen wie `render()`.
     *
     * @return array{ok: bool, stats: Stats, error?: 'render_failed'}
     */
    public function analyze(string $markdown): array
    {
        $run = $this->run($markdown, [], 'stats');

        return $run === null
            ? ['ok' => false, 'stats' => self::emptyStats(), 'error' => 'render_failed']
            : ['ok' => true, 'stats' => $run['stats']];
    }

    /**
     * Klartext für Suche und Hinweise: Text-, Code-, Codeblock- und (sichtbar escapte) HTML-Literale, Leerraum zusammengefasst.
     * Zitat-Blöcke (Marker, auch über der Zitatgrenze) nur mit `$withQuotes`; die Markerzeile entfällt immer. '' bei Fehler.
     */
    public function plainText(string $markdown, bool $withQuotes = false): string
    {
        $run = $this->run($markdown, [], $withQuotes ? 'text+quotes' : 'text');

        return $run === null ? '' : (string) $run['text'];
    }

    /** Ausgabe ohne Renderer und Cache: `<p class="cms-plain">…</p>`, escaped, Zeilenumbrüche als `<br />`. */
    public static function fallbackHtml(string $markdown): string
    {
        return '<p class="cms-plain">' . nl2br(htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')) . '</p>';
    }

    /** @return Stats mit allen Zählern 0 und leeren Listen */
    public static function emptyStats(): array
    {
        return ['links' => 0, 'external_links' => 0, 'images' => 0, 'external_images' => 0, 'attachments' => [], 'hosts' => [], 'mentions' => 0, 'quotes' => []];
    }

    /**
     * Einzige Host-Regel (Vertrag §4.7 Nr. 7) für `Stats.hosts`, `external_links` und den Site-Host: Prozent-Kodierung aufgelöst,
     * ASCII klein. Punycode bleibt Punycode; `www.` bleibt (verglichen wird exakt wie in `PurifierService::isForeignUrl()`).
     */
    public static function normalizeHost(string $host): string
    {
        return strtolower(rawurldecode($host));
    }

    /**
     * Speicherschätzung vor dem Parsen (Vertrag §4.7 Nr. 1): 4 MiB + 256 B je Byte + 5 KiB je Zeile + 4,5 KiB je Begrenzer
     * (einschließlich Tag-Anfängen und `www.`) und je `|`, höchstens MEMORY_CEILING.
     */
    public static function memoryNeed(int $bytes, int $lines, int $delimiters, int $pipes): int
    {
        return min(self::MEMORY_CEILING, 4194304 + 256 * $bytes + 5120 * $lines + 4608 * ($delimiters + $pipes));
    }

    // ── Ablauf ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Ein Aufruf: Parsen (mit Grenzen und Zählung), je nach Modus Rendern und Reinigen oder Klartext.
     *
     * @param 'html'|'stats'|'text'|'text+quotes' $mode
     * @return array{stats: Stats, html?: ?string, text?: string}|null null = Fehler (render_failed)
     */
    private function run(string $markdown, array $options, string $mode): ?array
    {
        if ($this->depth > 0) {
            return null;
        }
        $this->depth++;
        try {
            $document = $this->document($markdown, $options);
            if ($document === null) {
                return null;
            }
            $stats = $this->call['stats'];
            if ($mode === 'stats') {
                return ['stats' => $stats];
            }
            if ($mode !== 'html') {
                return ['stats' => $stats, 'text' => self::textOf($document, $mode === 'text+quotes')];
            }
            $this->call['rows'] = $this->attachmentRows($stats['attachments']);
            $raw = $this->renderer()->renderDocument($document)->getContent();

            return ['stats' => $stats, 'html' => $this->clean($raw, $markdown)];
        } catch (\Throwable $e) {
            if (($this->call['structure'] ?? false) !== true) {
                self::logFailure($e, $markdown);
            }

            return null;
        } finally {
            $this->call = [];
            $this->depth--;
        }
    }

    /**
     * Einzige Stelle, an der geparst wird (Vertrag §4.7 Nr. 1): Zeilenenden vereinheitlichen, Grenzen vor dem Parsen prüfen, parsen.
     * Die Listener auf `DocumentParsedEvent` prüfen die Struktur, markieren Zitate und Spoiler und zählen.
     */
    private function document(string $markdown, array $options): ?Document
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        if (!$this->withinLimits($markdown)) {
            return null;
        }
        $this->call = ['lines' => explode("\n", $markdown), 'options' => self::options($options), 'stats' => self::emptyStats(), 'rows' => [], 'structure' => false];

        return $this->parser()->parse($markdown);
    }

    /** Grenzen vor dem Parsen: UTF-8, Bytes, Zeilen, Begrenzer mit Tag-Anfängen und `www.`, Speicher. */
    private function withinLimits(string $markdown): bool
    {
        $bytes = strlen($markdown);
        if ($bytes > self::MAX_BYTES || !mb_check_encoding($markdown, 'UTF-8')) {
            return false;
        }
        $lines = substr_count($markdown, "\n") + 1;
        if ($lines > self::MAX_LINES) {
            return false;
        }
        $counts = count_chars($markdown, 1);
        $delimiters = (int) preg_match_all('~<[A-Za-z/!?]~', $markdown);
        // Autolink-Präfix `www.` (ohne Groß-/Kleinschreibung wie `InlineParserMatch::oneOf()`); `http://` und `https://` deckt die Bytezahl.
        $delimiters += (int) preg_match_all('~www\.~i', $markdown);
        foreach (str_split(self::DELIMITER_CHARS) as $char) {
            $delimiters += $counts[ord($char)] ?? 0;
        }
        if ($delimiters > self::MAX_DELIMITERS) {
            return false;
        }
        $free = ($this->headroom)();
        if ($free !== null && $free < self::memoryNeed($bytes, $lines, $delimiters, $counts[ord('|')] ?? 0)) {
            return false;
        }

        return true;
    }

    /** Optionen normalisieren: IDs nur ≥ 1, sonst null. */
    private static function options(array $options): array
    {
        $id = static fn (mixed $value): ?int => is_int($value) && $value > 0 ? $value : null;

        return ['post_id' => $id($options['post_id'] ?? null), 'uploader_id' => $id($options['uploader_id'] ?? null), 'images' => ($options['images'] ?? true) !== false];
    }

    /**
     * Anhangszeilen für die ersten MAX_ATTACHMENTS IDs (eine Abfrage über die Closure), nach ID.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function attachmentRows(array $ids): array
    {
        $ids = array_slice($ids, 0, self::MAX_ATTACHMENTS);
        if ($ids === []) {
            return [];
        }
        $rows = [];
        foreach (($this->attachments)($ids) as $row) {
            if (is_array($row) && isset($row['id']) && in_array((int) $row['id'], $ids, true)) {
                $rows[(int) $row['id']] = $row;
            }
        }

        return $rows;
    }

    /** R11: Reinigung im Profil `community`; '' für nicht leeres HTML gilt als Fehler (null). */
    private function clean(string $raw, string $markdown): ?string
    {
        try {
            $clean = ($this->purify)($raw);
        } catch (\Throwable $e) {
            self::logFailure($e, $markdown);

            return null;
        }
        if ($clean === null || ($clean === '' && trim($raw) !== '')) {
            return null;
        }

        return $clean;
    }

    private function parser(): MarkdownParser
    {
        if ($this->parser === null) {
            $this->parser = new MarkdownParser($this->environment());
        }

        return $this->parser;
    }

    private function renderer(): HtmlRenderer
    {
        return $this->htmlRenderer ??= new HtmlRenderer($this->environment());
    }

    /** CommonMark-Umgebung mit Erweiterungen, eigenen Renderern und Listenern (einmal je Instanz). */
    private function environment(): Environment
    {
        if ($this->environment !== null) {
            return $this->environment;
        }
        $environment = new Environment(self::CONFIG);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new TaskListExtension());

        $environment->addRenderer(Heading::class, self::node(fn (Node $node, ChildNodeRendererInterface $children): HtmlElement => $this->heading($node, $children)), 100);
        $environment->addRenderer(FencedCode::class, self::node(fn (Node $node): HtmlElement => $this->code($node)), 100);
        $environment->addRenderer(IndentedCode::class, self::node(fn (Node $node): HtmlElement => $this->code($node)), 100);
        $environment->addRenderer(Link::class, self::node(fn (Node $node, ChildNodeRendererInterface $children): HtmlElement|string|null => $this->link($node, $children)), 100);
        $environment->addRenderer(Image::class, self::node(fn (Node $node): HtmlElement|string => $this->image($node)), 100);
        $environment->addRenderer(BlockQuote::class, self::node(fn (Node $node, ChildNodeRendererInterface $children): HtmlElement|string => $this->blockQuote($node, $children)), 100);
        $environment->addRenderer(TaskListItemMarker::class, self::node(fn (Node $node): HtmlElement => $this->taskMarker($node)), 100);

        // Strukturgrenze vor Zitaten und Zählung (Vertrag §4.7 Nr. 2), danach Zitate, Spoiler und Zählung.
        $environment->addEventListener(DocumentParsedEvent::class, fn (DocumentParsedEvent $event) => $this->limitStructure($event->getDocument()), 100);
        $environment->addEventListener(DocumentParsedEvent::class, fn (DocumentParsedEvent $event) => $this->inspect($event->getDocument()));

        return $this->environment = $environment;
    }

    /** Eigener Renderer als Hülle um eine Closure dieser Klasse (die Closures dürfen private Methoden aufrufen). */
    private static function node(\Closure $render): NodeRendererInterface
    {
        return new class ($render) implements NodeRendererInterface {
            public function __construct(private readonly \Closure $render)
            {
            }

            public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string|null
            {
                return ($this->render)($node, $childRenderer);
            }
        };
    }

    // ── Listener ────────────────────────────────────────────────────────────────────────────────────────────────

    /** Knoten und Tabellenzellen zählen; über der Grenze eine LengthException (⇒ render_failed, Renderer und Purifier laufen nicht). */
    private function limitStructure(Document $document): void
    {
        $nodes = 0;
        $cells = 0;
        $walker = $document->walker();
        while (($event = $walker->next()) !== null) {
            if (!$event->isEntering()) {
                continue;
            }
            $nodes++;
            if ($event->getNode() instanceof TableCell) {
                $cells++;
            }
            if ($nodes > self::MAX_NODES || $cells > self::MAX_TABLE_CELLS) {
                $this->call['structure'] = true;

                throw new \LengthException('Struktur über der Grenze');
            }
        }
    }

    /** Zitat-Blöcke (R6, Zitatgrenze), Spoiler (R7), absolute Ziele (R4) und Zählung (Stats). */
    private function inspect(Document $document): void
    {
        $lines = $this->call['lines'];
        $quoteIds = [];
        foreach ($document->children() as $block) {
            if (!$block instanceof BlockQuote) {
                continue;
            }
            $id = QuoteService::markerId(self::sourceLine($lines, $block));
            if ($id === null) {
                continue;
            }
            self::dropMarkerLine($block);
            if (count($quoteIds) >= self::MAX_QUOTES) {
                $block->data->set('cms_overflow', true);
                continue;
            }
            $quoteIds[] = $id;
            $block->data->set('attributes', ['class' => 'cms-quote', 'data-quote-post' => (string) $id]);
        }
        foreach (self::allBlockQuotes($document) as $quote) {
            $this->markSpoiler($quote, $lines);
        }

        $stats = self::emptyStats();
        $stats['quotes'] = array_values(array_unique($quoteIds));
        $attachments = [];
        $hosts = [];
        $inLink = 0;
        $walker = $document->walker();
        while (($event = $walker->next()) !== null) {
            $node = $event->getNode();
            if ($node instanceof Link || $node instanceof Image) {
                // Der Walker meldet das Verlassen nur für Knoten mit Kindern.
                if (!$event->isEntering()) {
                    $inLink--;
                    continue;
                }
                $inLink += $node->hasChildren() ? 1 : 0;
                $node->setUrl(self::absolute($node->getUrl()));
                $url = $node->getUrl();
                $id = self::attachmentId($url);
                if ($node instanceof Image) {
                    $stats['images']++;
                }
                if ($id !== null) {
                    $attachments[$id] = $id;
                    continue;
                }
                if ($node instanceof Image) {
                    $stats['external_images']++;
                } else {
                    $stats['links']++;
                }
                [$external, $host] = $this->target($url);
                if ($external && $node instanceof Link) {
                    $stats['external_links']++;
                }
                if ($host !== null) {
                    $hosts[$host] = $host;
                }
                continue;
            }
            if ($event->isEntering() && $inLink === 0 && $node instanceof Text) {
                $stats['mentions'] += (int) preg_match_all(self::MENTION_PATTERN, $node->getLiteral());
            }
        }
        ksort($attachments);
        $stats['attachments'] = array_values($attachments);
        ksort($hosts, SORT_STRING);
        $stats['hosts'] = array_values($hosts);
        $this->call['stats'] = $stats;
    }

    /**
     * R7: ein Blockzitat der Tiefe k (nicht in Listen), dessen Quellzeile mit `>!` beginnt, wird ein Spoiler. Das führende `!` und
     * folgender Leerraum entfallen; ist der Text danach leer, entfällt auch der folgende Zeilenumbruch.
     *
     * @param list<string> $lines
     */
    private function markSpoiler(BlockQuote $quote, array $lines): void
    {
        $depth = 0;
        for ($parent = $quote->parent(); $parent !== null && !$parent instanceof Document; $parent = $parent->parent()) {
            if (!$parent instanceof BlockQuote) {
                return;
            }
            $depth++;
        }
        if ($quote->data->has('attributes/data-quote-post')
            || preg_match('/^(?:[ ]{0,3}>[ ]?){' . $depth . '}[ ]{0,3}>!(?:[ \t]|$)/', self::sourceLine($lines, $quote)) !== 1
            || !str_starts_with(self::firstText($quote), '!')) {
            return;
        }
        $paragraph = $quote->firstChild();
        $text = $paragraph?->firstChild();
        if (!$paragraph instanceof Paragraph || !$text instanceof Text) {
            return;
        }
        $rest = ltrim(substr($text->getLiteral(), 1), " \t");
        if ($rest !== '') {
            $text->setLiteral($rest);
        } else {
            $next = $text->next();
            $text->detach();
            if ($next instanceof Newline) {
                $next->detach();
            }
            if ($paragraph->firstChild() === null) {
                $paragraph->detach();
            }
        }
        $quote->data->set('cms_spoiler', true);
    }

    /** Alle Blockzitate des Dokuments, auch verschachtelte (für R7). @return list<BlockQuote> */
    private static function allBlockQuotes(Document $document): array
    {
        $quotes = [];
        $walker = $document->walker();
        while (($event = $walker->next()) !== null) {
            if ($event->isEntering() && $event->getNode() instanceof BlockQuote) {
                $quotes[] = $event->getNode();
            }
        }

        return $quotes;
    }

    /**
     * Quellzeile, in der ein Block beginnt (R6 und R7); '' ohne Zeilennummer.
     *
     * @param list<string> $lines
     */
    private static function sourceLine(array $lines, Node $block): string
    {
        $line = $block instanceof AbstractBlock ? $block->getStartLine() : null;

        return $line !== null ? ($lines[$line - 1] ?? '') : '';
    }

    /** Literal des ersten Textknotens im ersten Absatz eines Blocks ('' ohne), für R7. */
    private static function firstText(Node $block): string
    {
        $paragraph = $block->firstChild();
        $text = $paragraph instanceof Paragraph ? $paragraph->firstChild() : null;

        return $text instanceof Text ? $text->getLiteral() : '';
    }

    /** Markerzeile aus dem ersten Absatz entfernen (Knoten bis einschließlich des ersten Zeilenumbruchs); ein leerer Absatz entfällt. */
    private static function dropMarkerLine(BlockQuote $block): void
    {
        $paragraph = $block->firstChild();
        if (!$paragraph instanceof Paragraph || $paragraph->getStartLine() !== $block->getStartLine()) {
            return;
        }
        while (($child = $paragraph->firstChild()) !== null) {
            $child->detach();
            if ($child instanceof Newline) {
                break;
            }
        }
        if ($paragraph->firstChild() === null) {
            $paragraph->detach();
        }
    }

    /**
     * Ist das Ziel extern (zählt in `external_links`) und welcher fremde Host gehört dazu? `mailto:` zählt immer als extern
     * (Vertrag K4), sein Domainteil als Host; http(s) mit Host ≠ Site-Host ist extern, ein leerer Host zählt ohne Host
     * (fail-closed). Relativ, Anker und andere Schemata zählen nicht.
     *
     * @return array{0: bool, 1: ?string}
     */
    private function target(string $url): array
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):/', $url, $m) !== 1) {
            return [false, null];
        }
        $scheme = strtolower($m[1]);
        if ($scheme === 'mailto') {
            $address = (string) preg_replace('/[?#].*$/s', '', substr($url, 7));
            $at = strrpos($address, '@');
            $host = $at === false ? '' : self::normalizeHost(substr($address, $at + 1));

            return [true, $host !== '' && $host !== $this->siteHost() ? $host : null];
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            return [false, null];
        }
        $host = self::normalizeHost((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '' && $host === $this->siteHost()) {
            return [false, null];
        }

        return [true, $host !== '' ? $host : null];
    }

    private function siteHost(): string
    {
        return $this->siteHost ??= self::normalizeHost(defined('SITE_URL') ? (string) parse_url((string) SITE_URL, PHP_URL_HOST) : '');
    }

    // ── Renderer ────────────────────────────────────────────────────────────────────────────────────────────────

    /** R2: `#` → h3, alles tiefer → h4 (ATX und Setext). */
    private function heading(Node $node, ChildNodeRendererInterface $children): HtmlElement
    {
        if (!$node instanceof Heading) {
            throw new \InvalidArgumentException('Heading erwartet');
        }

        return new HtmlElement($node->getLevel() <= 1 ? 'h3' : 'h4', $node->data->get('attributes'), $children->renderNodes($node->children()));
    }

    /** R3: Code mit Sprachklasse nur aus der Allowlist; Inhalt escaped. */
    private function code(Node $node): HtmlElement
    {
        $attributes = [];
        $literal = '';
        if ($node instanceof FencedCode) {
            $word = strtolower((string) ($node->getInfoWords()[0] ?? ''));
            $language = self::LANGUAGES[$word] ?? null;
            if ($language !== null) {
                $attributes['class'] = 'language-' . $language;
            }
            $literal = $node->getLiteral();
        } elseif ($node instanceof IndentedCode) {
            $literal = $node->getLiteral();
        }

        return new HtmlElement('pre', [], new HtmlElement('code', $attributes, Xml::escape($literal)));
    }

    /** R4: Anhangslinks selbst, alle anderen Links der Standard-Renderer (Ziel schon absolut, unsichere Ziele ohne href). */
    private function link(Node $node, ChildNodeRendererInterface $children): HtmlElement|string|null
    {
        if (!$node instanceof Link || !str_starts_with($node->getUrl(), 'attachment:')) {
            return null;
        }
        $inner = $children->renderNodes($node->children());
        $id = self::attachmentId($node->getUrl());
        if ($id === null || !self::validAttachment($this->call['rows'][$id] ?? null, $this->call['options'])) {
            return $inner;
        }

        return new HtmlElement('a', ['href' => ($this->attachmentUrl)($id, false)], $inner);
    }

    /** R5: nur Anhänge werden eingebettet; fremde Bilder werden Links, unsichere Ziele Alternativtext. */
    private function image(Node $node): HtmlElement|string
    {
        if (!$node instanceof Image) {
            throw new \InvalidArgumentException('Image erwartet');
        }
        $alt = self::altText($node);
        $url = $node->getUrl();
        $nested = self::insideLink($node);
        if (str_starts_with($url, 'attachment:')) {
            $id = self::attachmentId($url);
            $row = $id !== null ? ($this->call['rows'][$id] ?? null) : null;
            if ($id === null || $row === null || !self::validAttachment($row, $this->call['options'])) {
                return Xml::escape($alt);
            }
            if ($this->call['options']['images'] && in_array((string) ($row['mime_type'] ?? ''), self::IMAGE_MIME, true)) {
                $attributes = ['src' => ($this->attachmentUrl)($id, (int) ($row['has_preview'] ?? 0) === 1), 'alt' => $alt];
                if (($row['width'] ?? null) !== null && ($row['height'] ?? null) !== null) {
                    $attributes['width'] = (string) (int) $row['width'];
                    $attributes['height'] = (string) (int) $row['height'];
                }
                $attributes['loading'] = 'lazy';
                $attributes['decoding'] = 'async';

                return new HtmlElement('img', $attributes, '', true);
            }
            $href = ($this->attachmentUrl)($id, false);

            return $nested ? Xml::escape($alt) : new HtmlElement('a', ['href' => $href], Xml::escape($alt !== '' ? $alt : $href));
        }
        if (!$nested && preg_match('#^https?://#i', $url) === 1 && !RegexHelper::isLinkPotentiallyUnsafe($url)) {
            return new HtmlElement('a', ['href' => $url], Xml::escape($alt !== '' ? $alt : $url));
        }

        return Xml::escape($alt);
    }

    /** Zitat-Block (R6), Überlauf der Zitatgrenze, Spoiler (R7) oder gewöhnliches Blockzitat. */
    private function blockQuote(Node $node, ChildNodeRendererInterface $children): HtmlElement|string
    {
        if ($node->data->has('cms_overflow')) {
            return '<blockquote class="cms-quote cms-quote--hidden"><p>' . QuoteService::OMISSION . '</p></blockquote>';
        }
        $inner = $children->renderNodes($node->children());
        $separator = $children->getInnerSeparator();
        $content = $inner === '' ? $separator : $separator . $inner . $separator;
        if ($node->data->has('cms_spoiler')) {
            return new HtmlElement('details', ['class' => 'cms-spoiler'], $separator . '<summary>' . self::SPOILER_SUMMARY . '</summary>' . $content);
        }

        return new HtmlElement('blockquote', $node->data->get('attributes'), $content);
    }

    /** R8: Aufgabenliste als Zeichen (der Purifier entfernt `<input>`). */
    private function taskMarker(Node $node): HtmlElement
    {
        $done = $node instanceof TaskListItemMarker && $node->isChecked();

        return new HtmlElement('span', ['class' => $done ? 'cms-task cms-task--done' : 'cms-task'], $done ? '☑' : '☐');
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * R4: protokollrelatives Ziel `//host` wird `https://host`; nach `http:`/`https:` werden beliebig viele `/` und `\` (auch keins)
     * zu `//` wie bei WHATWG-Sonderschemata (für Renderer und Zählung). Ohne `//` ergänzte der Purifier den Site-Host (URI.Host) und
     * hängte den Rest an: `https:-login.com` ergab `https://<Site>-login.com`, ohne Host in `Stats.hosts`.
     */
    private static function absolute(string $url): string
    {
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return (string) preg_replace('#^(https?):[\\\\/]*#i', '$1://', $url);
    }

    /** ID eines Anhangsziels `attachment:ID` (1 bis 4294967295), sonst null. */
    private static function attachmentId(string $url): ?int
    {
        if (preg_match(self::ATTACHMENT_PATTERN, $url, $m) !== 1 || (int) $m[1] > self::MAX_ATTACHMENT_ID) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * R5: Anhang des Beitrags (`post_id` gleich) oder noch unverknüpfter Upload des Schreibers (`post_id` NULL, `user_id` gleich).
     *
     * @param array<string, mixed>|null $row
     * @param array{post_id: ?int, uploader_id: ?int, images: bool} $options
     */
    private static function validAttachment(?array $row, array $options): bool
    {
        if ($row === null) {
            return false;
        }
        $postId = $options['post_id'];
        if ($postId !== null && ($row['post_id'] ?? null) !== null && (int) $row['post_id'] === $postId) {
            return true;
        }
        $uploaderId = $options['uploader_id'];

        return ($row['post_id'] ?? null) === null && $uploaderId !== null && (int) ($row['user_id'] ?? 0) === $uploaderId;
    }

    /** Alternativtext eines Bildes: Literale der Kinder, Zeilenumbruch als "\n" (wie CommonMarks ImageRenderer). */
    private static function altText(Image $image): string
    {
        $alt = '';
        $walker = $image->walker();
        while (($event = $walker->next()) !== null) {
            $node = $event->getNode();
            if (!$event->isEntering() || $node === $image) {
                continue;
            }
            if ($node instanceof StringContainerInterface) {
                $alt .= $node->getLiteral();
            } elseif ($node instanceof Newline) {
                $alt .= "\n";
            }
        }

        return $alt;
    }

    /** Liegt der Knoten in einem Link (kein Link im Link)? */
    private static function insideLink(Node $node): bool
    {
        for ($parent = $node->parent(); $parent !== null; $parent = $parent->parent()) {
            if ($parent instanceof Link) {
                return true;
            }
        }

        return false;
    }

    /**
     * Klartext eines Dokuments. Zitat-Blöcke (Marker und Überlauf) nur mit `$withQuotes`.
     */
    private static function textOf(Document $document, bool $withQuotes): string
    {
        $text = '';
        $walker = $document->walker();
        while (($event = $walker->next()) !== null) {
            $node = $event->getNode();
            if (!$event->isEntering()) {
                if ($node instanceof AbstractBlock) {
                    $text .= ' ';
                }
                continue;
            }
            if (!$withQuotes && $node instanceof BlockQuote && $node->parent() instanceof Document
                && ($node->data->has('attributes/data-quote-post') || $node->data->has('cms_overflow'))) {
                $walker->resumeAt($node, false);
                $walker->next();
                $text .= ' ';
                continue;
            }
            if ($node instanceof AbstractBlock) {
                $text .= ' ';
            }
            if ($node instanceof StringContainerInterface) {
                $text .= $node->getLiteral();
            } elseif ($node instanceof Newline) {
                $text .= ' ';
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** R12: Fehler ohne Inhalt protokollieren (Klasse der Ausnahme, Länge). */
    private static function logFailure(\Throwable $e, string $markdown): void
    {
        try {
            Logger::instance()->withChannel('community')->warning('ContentRenderer: Text konnte nicht verarbeitet werden.', [
                'exception' => $e::class,
                'length' => strlen($markdown),
            ]);
        } catch (\Throwable) {
            // Protokoll nicht verfügbar: das Ergebnis bleibt render_failed bzw. html null.
        }
    }

    /** Bytes aus einer ini-Angabe (`128M`, `1G`, `-1`); 0 oder kleiner = unbegrenzt. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
