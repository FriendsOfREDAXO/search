<?php

use FriendsOfRedaxo\Search\Index\IndexRepository;
use FriendsOfRedaxo\Search\Source\Source;
use FriendsOfRedaxo\Search\Source\SourceRepository;
use FriendsOfRedaxo\Search\Word\WordCollector;
use FriendsOfRedaxo\Search\Word\WordFormRepository;
use FriendsOfRedaxo\Search\Word\WordRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Baut die Wortliste aus dem vorhandenen Index auf, ohne neu zu indizieren.
 *
 * Gedacht fuer den Fall, der sonst teuer wuerde: nach einem Update ist der Index voll und
 * die Wortliste leer. Ohne diesen Befehl muesste alles neu indiziert werden -- also jedes
 * PDF neu extrahiert und jeder Artikel neu gerendert. Hier werden nur title und content
 * gelesen, gemessen rund zweieinhalb Sekunden fuer einen Index dieser Groessenordnung.
 *
 * Dient ausserdem als Reparatur, wenn die Liste aus irgendeinem Grund schief steht.
 *
 * Usage:
 *   bin/console search:words              alle Quellen
 *   bin/console search:words --source=3   eine Quelle
 */
final class rex_search_command_words extends rex_console_command
{
    private const BATCH = 200;

    protected function configure(): void
    {
        $this
            ->setDescription('Baut die Wortliste aus dem vorhandenen Index auf')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'ID einer einzelnen Quelle');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $words = new WordRepository();
        $forms = new WordFormRepository();

        $sourceId = $input->getOption('source');
        $sources = (new SourceRepository())->findAll();
        if (null !== $sourceId) {
            $sources = array_values(array_filter($sources, static fn ($s): bool => $s->requireId() === (int) $sourceId));
            if ([] === $sources) {
                $io->error('Quelle ' . $sourceId . ' gibt es nicht.');

                return rex_console_command::FAILURE;
            }
        }

        $gesamt = 0;
        foreach ($sources as $source) {
            if (!$source->getConfig('collect_words', true)) {
                $words->deleteSource($source->requireId());
                $forms->deleteSource($source->requireId());
                $io->writeln($source->name . ': übersprungen, liefert keine Wörter');
                continue;
            }

            $start = microtime(true);
            $collector = $this->collect($source);
            $written = $words->replaceSource($collector);
            $formCount = $forms->replaceSource($collector);
            $gesamt += $written;

            $io->writeln(sprintf(
                '%s: %d Wörter, %d Formen (%s) in %.2f s',
                $source->name,
                $written,
                $formCount,
                '' === $source->getLanguage() ? 'ohne Sprachangabe' : $source->getLanguage(),
                microtime(true) - $start,
            ));
        }

        $io->success(sprintf('Wortliste aufgebaut, %d Einträge.', $gesamt));

        return rex_console_command::SUCCESS;
    }

    private function collect(Source $source): WordCollector
    {
        $sourceId = $source->requireId();
        $collector = new WordCollector($sourceId, $source->getLanguage());
        $offset = 0;

        while (true) {
            $rows = rex_sql::factory()->getArray(
                'SELECT title, content FROM ' . IndexRepository::table()
                . ' WHERE source_id = :s ORDER BY id LIMIT ' . self::BATCH . ' OFFSET ' . $offset,
                ['s' => $sourceId],
            );
            if ([] === $rows) {
                break;
            }
            foreach ($rows as $row) {
                $collector->add((string) $row['title'] . ' ' . (string) $row['content']);
            }
            $offset += self::BATCH;
        }

        return $collector;
    }
}
