<?php

use FriendsOfRedaxo\Search\Chunk\ChunkBuilder;
use FriendsOfRedaxo\Search\Source\SourceRepository;
use FriendsOfRedaxo\Search\Vector\VectorSupport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Zerlegt den vorhandenen Index in Abschnitte, ohne neu zu indizieren.
 *
 * Gedacht fuer den Fall, der sonst teuer wuerde: die Zerlegung einer Quelle wird
 * umgestellt und soll neu greifen. Ohne diesen Befehl muesste dafuer der ganze Index
 * neu aufgebaut werden -- also jedes PDF neu extrahiert. Hier werden nur Titel,
 * Volltext und Originalinhalt gelesen.
 *
 * Abschnitte, deren Text sich dabei nicht aendert, behalten ihre Zeile und damit ihren
 * Vektor.
 *
 * Usage:
 *   bin/console search:chunks              alle Quellen
 *   bin/console search:chunks --source=3   eine Quelle
 */
final class rex_search_command_chunks extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Zerlegt den vorhandenen Index in Abschnitte')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'ID einer einzelnen Quelle');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);

        if (!VectorSupport::isAvailable()) {
            $io->error('Diese Datenbank kann keine Vektoren: ' . VectorSupport::getVersion()
                . '. Nötig ist MariaDB ' . VectorSupport::MIN_MARIADB . ' oder neuer.');

            return rex_console_command::FAILURE;
        }

        $sourceId = $input->getOption('source');
        $sources = (new SourceRepository())->findAll();
        if (null !== $sourceId) {
            $sources = array_values(array_filter($sources, static fn ($s): bool => $s->requireId() === (int) $sourceId));
            if ([] === $sources) {
                $io->error('Quelle ' . $sourceId . ' gibt es nicht.');

                return rex_console_command::FAILURE;
            }
        }

        $builder = new ChunkBuilder();
        $gesamt = 0;
        foreach ($sources as $source) {
            if (!$source->createsChunks()) {
                $builder->rebuildSource($source);
                $io->writeln($source->name . ': übersprungen, Zerlegung ist aus');
                continue;
            }

            $start = microtime(true);
            $result = $builder->rebuildSource($source);
            $gesamt += $result['chunks'];

            $io->writeln(sprintf(
                '%s: %d Abschnitte (%d neu, %d unverändert, %d entfernt) über %s in %.2f s',
                $source->name,
                $result['chunks'],
                $result['written'],
                $result['kept'],
                $result['deleted'],
                $source->getChunkStrategy()->getLabel(),
                microtime(true) - $start,
            ));
        }

        $io->success(sprintf('%d Abschnitte.', $gesamt));

        return rex_console_command::SUCCESS;
    }
}
