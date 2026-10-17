<?php

use FriendsOfRedaxo\Search\Passage\PassageQuery;
use FriendsOfRedaxo\Search\Passage\Retriever;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Zeigt, welche Abschnitte eine Frage beantworten wuerden.
 *
 * Beantwortet wird nichts -- das ist Sache des AddOns, das die Passagen abholt. Der
 * Befehl zeigt genau das, was eine Antwortmaschine als Kontext bekaeme, und ist damit
 * das Werkzeug, um Schwelle und Zerlegung zu beurteilen: zu wenig Passagen heisst
 * Schwelle zu hoch oder Quelle nicht eingebettet, Passagen voller Tabellenfragmente
 * heissen, dass die Zerlegung dieser Quelle nicht passt.
 *
 * Usage:
 *   bin/console search:ask "Wie hoch ist die Gewerbesteuer?"
 *   bin/console search:ask "..." --source=19 --min=0.4 --passages=5
 *   bin/console search:ask "..." --context
 */
final class rex_search_command_ask extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Zeigt die Abschnitte, die eine Frage beantworten würden')
            ->addArgument('question', InputArgument::REQUIRED, 'Die Frage')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Nur diese Quelle')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Nur diese Sprache')
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Mindestähnlichkeit, 0 bis 1')
            ->addOption('passages', 'p', InputOption::VALUE_REQUIRED, 'Höchstens so viele Passagen')
            ->addOption('context', null, InputOption::VALUE_NONE, 'Den fertigen Kontextblock ausgeben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $retriever = new Retriever();

        if (!$retriever->isReady()) {
            $io->error('Noch nicht bereit: MariaDB ab 11.7, ein Embedding-Profil in den Einstellungen und erzeugte Vektoren.');

            return rex_console_command::FAILURE;
        }

        $query = PassageQuery::create((string) $input->getArgument('question'));
        if (null !== $input->getOption('source')) {
            $query = $query->withSourceIds((int) $input->getOption('source'));
        }
        if (null !== $input->getOption('language')) {
            $query = $query->withLanguages((string) $input->getOption('language'));
        }
        if (null !== $input->getOption('min')) {
            $query = $query->withMinSimilarity((float) $input->getOption('min'));
        }
        if (null !== $input->getOption('passages')) {
            $query = $query->withMaxPassages((int) $input->getOption('passages'));
        }

        $start = microtime(true);
        $result = $retriever->retrieve($query);
        $duration = microtime(true) - $start;

        if ($result->isEmpty()) {
            $io->warning(sprintf(
                'Keine Passage über der Schwelle von %.2f. Der nächste Abschnitt kam auf %.3f.',
                $query->getMinSimilarity(),
                $result->bestSimilarity,
            ));

            return rex_console_command::SUCCESS;
        }

        if ($input->getOption('context')) {
            $output->writeln($result->toPromptContext());

            return rex_console_command::SUCCESS;
        }

        $rows = [];
        foreach ($result as $passage) {
            $rows[] = [
                number_format($passage->similarity, 3),
                mb_strimwidth($passage->getLabel(), 0, 50, '…'),
                $passage->getCharacters(),
                mb_strimwidth(str_replace("\n", ' ', $passage->text), 0, 60, '…'),
            ];
        }
        $io->table(['Ähnl.', 'Herkunft', 'Zeichen', 'Anfang'], $rows);

        $io->writeln(sprintf(
            '%d von %d Passagen über der Schwelle, %d Zeichen, %.0f ms.%s',
            $result->count(),
            $result->eligible,
            $result->getCharacters(),
            1000 * $duration,
            $result->truncated ? ' Der Rest passte nicht mehr hinein.' : '',
        ));

        return rex_console_command::SUCCESS;
    }
}
