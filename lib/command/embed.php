<?php

use FriendsOfRedaxo\Search\Source\SourceRepository;
use FriendsOfRedaxo\Search\Vector\Embedder;
use FriendsOfRedaxo\Search\Vector\EmbeddingClient;
use FriendsOfRedaxo\Search\Vector\VectorSupport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Erzeugt die fehlenden Vektoren.
 *
 * Laeuft so lange, bis nichts mehr offen ist, oder bis das mitgegebene Budget erschoepft
 * ist. Ein Abbruch ist harmlos: offen ist, was keinen Vektor hat, und der naechste Lauf
 * macht genau dort weiter.
 *
 * Usage:
 *   bin/console search:embed
 *   bin/console search:embed --source=20
 *   bin/console search:embed --limit=200 --seconds=60
 */
final class rex_search_command_embed extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Erzeugt die fehlenden Vektoren der Abschnitte')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'ID einer einzelnen Quelle')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Höchstens so viele Abschnitte')
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Höchstens so viele Sekunden');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);

        if (!VectorSupport::isAvailable()) {
            $io->error('Diese Datenbank kann keine Vektoren: ' . VectorSupport::getVersion()
                . '. Nötig ist MariaDB ' . VectorSupport::MIN_MARIADB . ' oder neuer.');

            return rex_console_command::FAILURE;
        }
        if (!EmbeddingClient::isConfigured()) {
            $io->error('Kein Embedding-Profil eingestellt. Suche › Einstellungen.');

            return rex_console_command::FAILURE;
        }

        $sourceId = null;
        if (null !== $input->getOption('source')) {
            $sourceId = (int) $input->getOption('source');
            if (null === (new SourceRepository())->find($sourceId)) {
                $io->error('Quelle ' . $sourceId . ' gibt es nicht.');

                return rex_console_command::FAILURE;
            }
        }

        $embedder = new Embedder();
        $open = $embedder->countOpen($sourceId);
        if (0 === $open) {
            $io->success('Nichts offen.');

            return rex_console_command::SUCCESS;
        }

        $io->writeln(sprintf('%d Abschnitte offen, Modell %s.', $open, EmbeddingClient::getModel()));

        $result = $embedder->run(
            $sourceId,
            (int) $input->getOption('limit'),
            (float) $input->getOption('seconds'),
        );

        $io->writeln($result->describe());

        if (0 < $result->failed) {
            $io->warning('Nicht alles ist durchgelaufen. Ein erneuter Aufruf nimmt die offenen Abschnitte wieder mit.');

            return rex_console_command::FAILURE;
        }

        $io->success($result->isDone() ? 'Alle Abschnitte haben einen Vektor.' : sprintf('%d Abschnitte bleiben offen.', $result->remaining));

        return rex_console_command::SUCCESS;
    }
}
