<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Analysis;

use FriendsOfRedaxo\Search\Language;
use Loupe\Matcher\Tokenizer\LocaleConfiguration\English;
use Loupe\Matcher\Tokenizer\LocaleConfiguration\German;
use Loupe\Matcher\Tokenizer\LocaleConfiguration\LocaleConfigurationInterface;
use Loupe\Matcher\Tokenizer\Tokenizer;
use Throwable;
use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

use function array_key_exists;
use function class_exists;
use function extension_loaded;
use function strlen;

/**
 * Bringt ein Wort auf seine Nachschlageformen.
 *
 * Die Form ist immer ein Stamm: der des ganzen Wortes und zusaetzlich der jedes Teils,
 * in den sich ein zusammengesetztes Wort zerlegen laesst. "Burgruine" ergibt damit
 * "burgruin", "burg" und "ruin", und weil die Suche dieselbe Funktion auf das eingegebene
 * Wort anwendet, findet "Ruinen" ueber "ruin" sowohl "Ruine" als auch "Burgruine".
 *
 * Dass beides -- Beugung und Zusammensetzung -- auf denselben Nenner gebracht wird, ist
 * der Kern: sonst braeuchte die Suche zwei Nachschlagewege und wuerde "Burgruine" nur bei
 * exakt getipptem "Ruine" finden, nicht bei "Ruinen".
 *
 * Zwei Stolperstellen, die hier bewusst in Kauf genommen werden:
 *
 * Der Snowball-Stammbildner fuer Deutsch faltet Umlaute, bevor er Endungen abschneidet.
 * "Bürger" und "Burg" bekommen dadurch denselben Stamm "burg". Das ist eine echte
 * Fehlverschmelzung und nicht zu reparieren, ohne den Stammbildner zu ersetzen. Getragen
 * wird sie davon, dass die Erweiterung erst anlaeuft, wenn die Suche zu wenig gefunden
 * hat, und dass die exakte Wortform in der Bewertung einen Zuschlag bekommt.
 *
 * Die Zerlegung arbeitet in ihrer eigenen Umlautschreibweise ("ö" zu "oe"), der Rest des
 * AddOns in einer anderen ("ö" zu "o", siehe FriendsOfRedaxo\Search\Word\WordFolder). Das
 * stoert nicht, weil die Form nirgends mit einer gefalteten Form verglichen wird: beim
 * Schreiben und beim Suchen laeuft dasselbe Wort durch dieselbe Funktion.
 */
final class Analyzer
{
    /**
     * Sprachen, fuer die eine Wortzerlegung vorliegt. Der Stammbildner kann mehr Sprachen,
     * siehe {@see Language}; zerlegen laesst sich nur, wofuer ein Woerterbuch dabei ist.
     *
     * @var array<string, class-string<LocaleConfigurationInterface>>
     */
    private const DECOMPOUNDERS = [
        'de' => German::class,
        'en' => English::class,
    ];

    /** @var array<string, self|null> */
    private static array $instances = [];

    private static ?string $cacheDirectory = null;

    private ?Tokenizer $tokenizer = null;

    private function __construct(
        private readonly string $language,
        private readonly Stemmer $stemmer,
    ) {}

    /**
     * Die Analyse fuer eine Sprache, oder null wenn es dafuer keine gibt.
     */
    public static function forLanguage(string $language): ?self
    {
        if (array_key_exists($language, self::$instances)) {
            return self::$instances[$language];
        }

        return self::$instances[$language] = self::create($language);
    }

    /**
     * Ob fuer diese Sprache ueberhaupt etwas analysiert werden kann.
     */
    public static function supports(string $language): bool
    {
        return null !== self::forLanguage($language);
    }

    /**
     * Ob die Zerlegung zusammengesetzter Woerter moeglich ist. Sie braucht ein
     * Woerterbuch und ext-intl, der Stammbildner beides nicht.
     */
    public static function canDecompound(string $language): bool
    {
        return isset(self::DECOMPOUNDERS[$language])
            && extension_loaded('intl')
            && class_exists(Tokenizer::class);
    }

    public static function hasStemmer(string $language): bool
    {
        return null !== self::stemmer($language);
    }

    /**
     * Wohin die Zerlegung ihr vorbereitetes Woerterbuch legen darf.
     *
     * Ohne Angabe schreibt sie in das Verzeichnis der Bibliothek, also nach vendor/ -- auf
     * einem Server mit schreibgeschuetztem vendor faellt sie damit aus.
     */
    public static function setCacheDirectory(?string $directory): void
    {
        self::$cacheDirectory = $directory;
        self::reset();
    }

    /**
     * Nur fuer Tests und fuer die Diagnoseseite nach einer Aenderung.
     */
    public static function reset(): void
    {
        self::$instances = [];
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * Die Nachschlageformen eines Wortes, Stamm des Ganzen zuerst.
     *
     * @return list<string>
     */
    public function forms(string $word): array
    {
        $word = mb_strtolower(trim($word));
        if ('' === $word) {
            return [];
        }

        $forms = [];
        $this->collect($forms, $this->stem($word));
        foreach ($this->parts($word) as $part) {
            $this->collect($forms, $this->stem($part));
        }

        return array_keys($forms);
    }

    /**
     * Die Teile eines zusammengesetzten Wortes, leer wenn es keines ist.
     *
     * @return list<string>
     */
    public function parts(string $word): array
    {
        $tokenizer = $this->tokenizer();
        if (null === $tokenizer) {
            return [];
        }

        try {
            $tokens = $tokenizer->tokenize($word, true, 5);
        } catch (Throwable) {
            return [];
        }

        $parts = [];
        foreach ($tokens->all() as $token) {
            foreach ($token->getVariants() as $variant) {
                $parts[$variant] = true;
            }
        }

        return array_keys($parts);
    }

    public function stem(string $word): string
    {
        try {
            return mb_strtolower($this->stemmer->stem($word));
        } catch (Throwable) {
            // Der Stammbildner stolpert ueber einzelne Eingaben; dann ist das Wort selbst
            // die beste verfuegbare Form.
            return $word;
        }
    }

    /**
     * @param array<string, true> $forms
     */
    private function collect(array &$forms, string $form): void
    {
        // Die Spalte fasst 96 Zeichen; laengere Formen gibt es nicht, weil die Wortliste
        // schon bei 40 Zeichen abschneidet. Die Pruefung ist trotzdem da, damit eine
        // Fremdquelle ueber einen Extension Point die Abfrage nicht zum Abbruch bringt.
        if ('' !== $form && 96 >= strlen($form)) {
            $forms[$form] = true;
        }
    }

    private function tokenizer(): ?Tokenizer
    {
        if (null !== $this->tokenizer) {
            return $this->tokenizer;
        }
        if (!self::canDecompound($this->language)) {
            return null;
        }

        $class = self::DECOMPOUNDERS[$this->language];

        try {
            // Zwischenformen behalten: ohne sie verliert "Bandmaterial" den Teil
            // "material" und "Bestandsaufnahme" den Teil "bestand".
            return $this->tokenizer = new Tokenizer(new $class(true, self::$cacheDirectory));
        } catch (Throwable) {
            return null;
        }
    }

    private static function create(string $language): ?self
    {
        if (Language::UNKNOWN === $language) {
            return null;
        }

        $stemmer = self::stemmer($language);

        return null === $stemmer ? null : new self($language, $stemmer);
    }

    private static function stemmer(string $language): ?Stemmer
    {
        if (Language::UNKNOWN === $language || !class_exists(StemmerFactory::class)) {
            return null;
        }

        try {
            return StemmerFactory::create($language);
        } catch (Throwable) {
            return null;
        }
    }
}
