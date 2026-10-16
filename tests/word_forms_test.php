<?php

use FriendsOfRedaxo\Search\Analysis\Analyzer;
use FriendsOfRedaxo\Search\Analysis\WordForms;
use PHPUnit\Framework\TestCase;

/** @internal */
final class rex_search_word_forms_test extends TestCase
{
    protected function setUp(): void
    {
        Analyzer::setCacheDirectory(sys_get_temp_dir() . '/rex-search-test-dictionaries');
    }

    public function testEveryFormKeepsItsWordAndItsDocumentCount(): void
    {
        $rows = WordForms::build([['word' => 'Burgruine', 'docs' => 3]], Analyzer::forLanguage('de'));

        self::assertSame([
            ['form' => 'burgruin', 'word' => 'Burgruine', 'docs' => 3],
            ['form' => 'burg', 'word' => 'Burgruine', 'docs' => 3],
            ['form' => 'ruin', 'word' => 'Burgruine', 'docs' => 3],
        ], $rows);
    }

    /**
     * Ohne Analyse entstehen keine Zeilen. Die Suche faellt dann auf die Suche nach
     * enthaltenen Teilen zurueck -- eine halb gefuellte Formtabelle waere schlimmer, sie
     * saehe vollstaendig aus.
     */
    public function testWithoutAnAnalyzerNothingIsBuilt(): void
    {
        self::assertSame([], WordForms::build([['word' => 'Burgruine', 'docs' => 3]], null));
    }

    public function testTwoWordsCanShareAForm(): void
    {
        $rows = WordForms::build(
            [['word' => 'Ruine', 'docs' => 2], ['word' => 'Ruinen', 'docs' => 5]],
            Analyzer::forLanguage('de'),
        );

        $byForm = [];
        foreach ($rows as $row) {
            $byForm[$row['form']][] = $row['word'];
        }

        self::assertSame(['ruin' => ['Ruine', 'Ruinen']], $byForm);
    }

    public function testAnEmptyWordListYieldsNoRows(): void
    {
        self::assertSame([], WordForms::build([], Analyzer::forLanguage('de')));
    }
}
