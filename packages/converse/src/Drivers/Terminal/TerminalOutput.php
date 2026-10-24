<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Terminal;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

use function Termwind\parse;

class TerminalOutput
{
    protected const int MAX_WIDTH = 67;

    public function __construct(protected OutputInterface $output) {}

    public function render(string $html): void
    {
        if ($html === '') {
            return;
        }

        $this->output->writeln(parse('<div>'.$html.'</div>'));
    }

    public function width(): int
    {
        return min((new Terminal)->getWidth(), static::MAX_WIDTH) - 4;
    }
}
