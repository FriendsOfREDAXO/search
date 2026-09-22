<?php

use FriendsOfRedaxo\Search\Index\Indexer;
use FriendsOfRedaxo\Search\Source\SourceRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Usage:
 *   bin/console search:index              alle aktiven Quellen
 *   bin/console search:index --source=3   eine Quelle
 *   bin/console search:index --all        auch inaktive Quellen
 */
final class rex_search_command_index extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Baut den Volltextindex der konfigurierten Quellen neu auf')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'ID einer einzelnen Quelle')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Auch inaktive Quellen indizieren');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $repository = new SourceRepository();

        $sourceId = $input->getOption('source');
        if (null !== $sourceId) {
            $source = $repository->find((int) $sourceId);
            if (null === $source) {
                $io->error('Quelle ' . $sourceId . ' nicht gefunden.');

                return 1;
            }
            $sources = [$source];
        } else {
            $sources = $repository->findAll(!$input->getOption('all'));
        }

        if ([] === $sources) {
            $io->warning('Keine Quellen vorhanden.');

            return 0;
        }

        $indexer = new Indexer();
        $failed = 0;
        foreach ($sources as $source) {
            $io->section($source->name . ' [' . $source->typeKey . ']');
            try {
                $result = $indexer->rebuild($source, static function (int $done, ?int $total) use ($io): void {
                    $io->writeln(sprintf('  %d%s', $done, null === $total ? '' : ' / ' . $total));
                });
                $io->writeln(sprintf('  %d Datensätze, %d Dokumente geschrieben, %d veraltete entfernt', $result->items, $result->documentsWritten, $result->documentsDeleted));
            } catch (Throwable $exception) {
                ++$failed;
                $io->error($exception->getMessage());
            }
        }

        if ($failed > 0) {
            $io->error($failed . ' Quelle(n) mit Fehlern.');

            return 1;
        }

        $io->success('Index aufgebaut.');

        return 0;
    }
}
