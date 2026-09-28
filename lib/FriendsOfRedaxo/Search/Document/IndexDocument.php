<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\Search\Document;

use DateTimeImmutable;

use const JSON_THROW_ON_ERROR;

/**
 * Ein Eintrag im Volltextindex, so wie ihn ein Quellentyp erzeugt.
 *
 * $itemId identifiziert den Datensatz der Quelle (z. B. die YForm-ID oder "artikel-clang"),
 * $indexId das konkrete Dokument daraus (z. B. "17-content" oder "42-1-fullcontent").
 * Ein Datensatz kann mehrere Dokumente liefern; beim Neuaufbau eines Datensatzes werden
 * alle Dokumente mit derselben $itemId ersetzt.
 */
final class IndexDocument
{
    /** Der Originalinhalt ist Markup und darf nicht ungeprueft ausgegeben werden. */
    public const FORMAT_HTML = 'html';

    /** Der Originalinhalt ist Klartext; ein "<" darin ist ein Zeichen, kein Tag. */
    public const FORMAT_TEXT = 'text';

    /*
     * Zur Wahl des Formats: HTML nur dort angeben, wo Markup zur Bauart gehoert, also beim
     * Inhalt eines Artikels. Feldwerte aus YForm oder aus Metainfos gelten als Klartext.
     * Die umgekehrte Verwechslung waere teurer: wer Klartext als Markup behandelt, verliert
     * aus "5 < 10" den halben Satz, waehrend Markup als Klartext nur unschoen aussieht.
     */

    /**
     * $rawContent ist der Inhalt vor der Umwandlung in Klartext, also etwa das gerenderte
     * HTML eines Artikels. Er liegt bewusst neben $content und ist nicht Teil des
     * Volltextindex: die Suche arbeitet auf dem Klartext, die Weiterverarbeitung, etwa das
     * Zerlegen fuer Embeddings, braucht dagegen die Struktur. $rawFormat sagt, ob es sich um
     * Markup oder um Klartext handelt, siehe FORMAT_HTML und FORMAT_TEXT.
     *
     * @param array<string, mixed> $meta
     * @param self::FORMAT_*|null $rawFormat
     */
    public function __construct(
        public readonly string $itemId,
        public readonly string $indexId,
        public readonly string $title,
        public readonly string $content,
        public readonly string $url = '',
        public readonly ?DateTimeImmutable $updatedAt = null,
        public readonly ?int $clangId = null,
        public readonly array $meta = [],
        public readonly ?string $rawContent = null,
        public readonly ?string $rawFormat = null,
    ) {}

    public function getContentHash(): string
    {
        return sha1(implode("\0", [
            $this->title,
            $this->content,
            $this->url,
            (string) $this->clangId,
            json_encode($this->meta, JSON_THROW_ON_ERROR),
            (string) $this->rawContent,
            (string) $this->rawFormat,
        ]));
    }
}
