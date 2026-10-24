<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Terminal;

use Illuminate\Support\Str;

class MarkdownRenderer
{
    protected const string BREAK = "\n";

    protected const string FENCE = '/^\s*(`{3,}|~{3,})\s*([\w+#.-]*)/';

    protected const string HEADING = '/^\s{0,3}#{1,6}\s+(.*?)(?:\s+#+)?\s*$/';

    protected const string RULE = '/^\s{0,3}([-*_])(\s*\1){2,}\s*$/';

    protected const string QUOTE = '/^\s{0,3}>\s?/';

    protected const string ITEM = '/^(\s*)([-*+]|\d{1,9}[.)])\s+(.*)$/';

    protected const string TASK = '/^\[([ xX])\]\s+/';

    protected const string TABLE_RULE = '/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/';

    protected const string INLINE = '/(?<code>`[^`\n]+`)'
        .'|\*\*(?<bold>.+?)\*\*'
        .'|~~(?<strike>.+?)~~'
        .'|(?<![\w*])\*(?![\s*])(?<italic>[^*\n]+?)(?<!\s)\*(?!\w)'
        .'|(?<!\w)_(?![\s_])(?<underscored>[^_\n]+?)(?<!\s)_(?!\w)'
        .'|(?<image>!)?\[(?<label>[^\]\n]+)\]\((?<url>[^)\s]+)\)'
        .'|<(?<autolink>https?:\/\/[^>\s]+)>'
        .'|(?<bare>https?:\/\/[^\s<>()]*[^\s<>().,;:!?\'"])/u';

    public static function render(string $markdown, int $width): string
    {
        return collect((new static)->blocks(explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)), max($width, 1)))
            ->map(fn (string $line): string => Html::block($line, 'flex'))
            ->implode('');
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    protected function blocks(array $lines, int $width): array
    {
        $output = [];
        $gap = false;
        $index = 0;

        while ($index < count($lines)) {
            if (Str::trim($lines[$index]) === '') {
                $gap = $output !== [];
                $index++;

                continue;
            }

            [$block, $index] = $this->block($lines, $index, $width);

            array_push($output, ...($gap ? [''] : []), ...$block);
            $gap = false;
        }

        return $output;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    protected function block(array $lines, int $index, int $width): array
    {
        $line = $lines[$index];

        return match (true) {
            preg_match(static::FENCE, $line) === 1 => $this->fence($lines, $index, $width),
            preg_match(static::HEADING, $line, $heading) === 1 => [$this->wrap($this->inline($heading[1], 'font-bold'), $width), $index + 1],
            preg_match(static::RULE, $line) === 1 => [['<span class="text-gray">'.str_repeat('─', $width).'</span>'], $index + 1],
            preg_match(static::QUOTE, $line) === 1 => $this->quote($lines, $index, $width),
            preg_match(static::ITEM, $line) === 1 => $this->item($lines, $index, $width),
            $this->startsTable($lines, $index) => $this->table($lines, $index),
            default => $this->paragraph($lines, $index, $width),
        };
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function starts(array $lines, int $index): bool
    {
        return Str::trim($lines[$index]) === ''
            || preg_match(static::FENCE, $lines[$index]) === 1
            || preg_match(static::HEADING, $lines[$index]) === 1
            || preg_match(static::RULE, $lines[$index]) === 1
            || preg_match(static::QUOTE, $lines[$index]) === 1
            || preg_match(static::ITEM, $lines[$index]) === 1
            || $this->startsTable($lines, $index);
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function startsTable(array $lines, int $index): bool
    {
        return Str::contains($lines[$index], '|') && preg_match(static::TABLE_RULE, $lines[$index + 1] ?? '') === 1;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    protected function paragraph(array $lines, int $index, int $width): array
    {
        $text = [$lines[$index++]];

        while ($index < count($lines) && ! $this->starts($lines, $index)) {
            $text[] = $lines[$index++];
        }

        return [$this->wrap($this->inline(implode(static::BREAK, array_map(Str::trim(...), $text))), $width), $index];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    protected function fence(array $lines, int $index, int $width): array
    {
        preg_match(static::FENCE, $lines[$index], $fence);

        $code = [];

        while (++$index < count($lines) && ! Str::startsWith(Str::trim($lines[$index]), $fence[1])) {
            $code[] = Str::replace("\t", '    ', $lines[$index]);
        }

        return [[
            ...($fence[2] === '' ? [] : ['<span class="text-gray">'.Html::escape($fence[2]).'</span>']),
            ...collect($code)
                ->flatMap(fn (string $line): array => static::chunks($line, $width - 2))
                ->map(fn (string $line): string => '<span class="text-gray">│&nbsp;</span><span class="text-yellow">'.Html::escape($line).'</span>')
                ->all(),
        ], $index + 1];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    protected function quote(array $lines, int $index, int $width): array
    {
        $quoted = [];

        while ($index < count($lines) && preg_match(static::QUOTE, $lines[$index]) === 1) {
            $quoted[] = (string) preg_replace(static::QUOTE, '', $lines[$index++]);
        }

        return [array_map(fn (string $line): string => '<span class="text-gray">│&nbsp;</span>'.$line, $this->blocks($quoted, $width - 2)), $index];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    protected function item(array $lines, int $index, int $width): array
    {
        preg_match(static::ITEM, $lines[$index++], $item);

        $text = [$item[3]];

        while ($index < count($lines) && ! $this->starts($lines, $index) && preg_match('/^\s/', $lines[$index]) === 1) {
            $text[] = Str::trim($lines[$index++]);
        }

        $indent = min(mb_strlen($item[1]), intdiv($width, 2));
        $marker = (Str::endsWith($item[2], ['.', ')']) ? $item[2] : '•').' ';
        $body = implode(static::BREAK, $text);

        if (preg_match(static::TASK, $body, $task) === 1) {
            $body = ($task[1] === ' ' ? '☐ ' : '☑ ').Str::after($body, $task[0]);
        }

        $hanging = $indent + mb_strlen($marker);

        return [array_map(fn (string $line, int $row): string => '<span class="text-gray">'
            .str_replace(' ', '&nbsp;', Html::escape(str_repeat(' ', $indent).($row === 0 ? $marker : str_repeat(' ', mb_strlen($marker)))))
            .'</span>'.$line, $wrapped = $this->wrap($this->inline($body), $width - $hanging), array_keys($wrapped)), $index];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    protected function table(array $lines, int $index): array
    {
        $rows = [$this->cells($lines[$index])];
        $index += 2;

        while ($index < count($lines) && Str::contains($lines[$index], '|') && Str::trim($lines[$index]) !== '') {
            $rows[] = $this->cells($lines[$index++]);
        }

        $widths = array_map(fn (int $column): int => max(array_map(fn (array $row): int => mb_strwidth($row[$column] ?? ''), $rows)), array_keys($rows[0]));

        $render = fn (array $row, string $classes): string => '<span class="'.$classes.'">'.Html::escape(rtrim(implode('   ', array_map(
            fn (int $column): string => Html::pad($row[$column] ?? '', $widths[$column]),
            array_keys($widths),
        )))).'</span>';

        return [[
            $render($rows[0], 'font-bold'),
            '<span class="text-gray">'.Html::escape(implode('   ', array_map(fn (int $width): string => str_repeat('─', $width), $widths))).'</span>',
            ...array_map(fn (array $row): string => $render($row, ''), array_slice($rows, 1)),
        ], $index];
    }

    /** @return array<int, string> */
    protected function cells(string $line): array
    {
        return array_map(
            fn (string $cell): string => implode('', array_column($this->inline(Str::trim($cell)), 0)),
            explode('|', Str::trim(Str::trim($line), '|')),
        );
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> */
    protected function inline(string $text, string $classes = '', string $href = ''): array
    {
        preg_match_all(static::INLINE, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        $segments = [];
        $offset = 0;

        foreach ($matches as $match) {
            array_push($segments, [substr($text, $offset, $match[0][1] - $offset), $classes, $href], ...$this->token($match, $classes, $href));
            $offset = $match[0][1] + strlen((string) $match[0][0]);
        }

        return array_values(array_filter([...$segments, [substr($text, $offset), $classes, $href]], fn (array $segment): bool => $segment[0] !== ''));
    }

    /**
     * @param  array<int|string, array{0: string|null, 1: int}>  $match
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    protected function token(array $match, string $classes, string $href): array
    {
        $group = fn (string $name): ?string => $match[$name][0] ?? null;
        $style = fn (string $class): string => Str::squish($classes.' '.$class);

        return match (true) {
            $group('code') !== null => [[Str::trim($group('code'), '`'), $style('text-yellow'), $href]],
            $group('bold') !== null => $this->inline($group('bold'), $style('font-bold'), $href),
            $group('strike') !== null => $this->inline($group('strike'), $style('line-through'), $href),
            $group('italic') !== null || $group('underscored') !== null => $this->inline((string) ($group('italic') ?? $group('underscored')), $style('italic'), $href),
            $group('image') !== null => [['['.$group('label').']', $style('text-gray'), $href]],
            $group('url') !== null => [
                ...$this->inline((string) $group('label'), $style('underline'), $group('url')),
                ...($group('label') === $group('url') ? [] : [[' ('.$group('url').')', 'text-gray', $group('url')]]),
            ],
            default => [[(string) ($group('autolink') ?? $group('bare')), $style('underline'), (string) ($group('autolink') ?? $group('bare'))]],
        };
    }

    protected static function segment(string $text, string $classes, string $href): string
    {
        $text = Str::trim($text) === '' ? $text : Html::escape($text);

        return $href === ''
            ? '<span class="'.$classes.'">'.$text.'</span>'
            : '<a class="'.$classes.'" href="'.Html::escape($href).'">'.$text.'</a>';
    }

    /** @return array<int, string> */
    protected static function chunks(string $text, int $width): array
    {
        $chunks = [];

        while (mb_strwidth($text) > $width && ($chunk = mb_strimwidth($text, 0, max($width, 1)) ?: mb_substr($text, 0, 1)) !== $text) {
            $chunks[] = $chunk;
            $text = mb_substr($text, mb_strlen($chunk));
        }

        return [...$chunks, $text];
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $line
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    protected static function trimmed(array $line): array
    {
        while ($line !== [] && Str::trim(end($line)[0]) === '') {
            array_pop($line);
        }

        return $line;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $segments
     * @return array<int, string>
     */
    protected function wrap(array $segments, int $width): array
    {
        $lines = collect();
        $line = [];
        $used = 0;

        foreach ($segments as [$text, $classes, $href]) {
            foreach (preg_split('/(\n| +)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if ($word === static::BREAK || ($used > 0 && $used + mb_strwidth($word) > $width && Str::trim($word) !== '')) {
                    $lines->push(static::trimmed($line));
                    [$line, $used] = [[], 0];
                }

                if ($word === static::BREAK || ($used === 0 && Str::trim($word) === '')) {
                    continue;
                }

                foreach (static::chunks($word, $width) as $index => $chunk) {
                    if ($index > 0) {
                        $lines->push(static::trimmed($line));
                        [$line, $used] = [[], 0];
                    }

                    $line[] = [$chunk, $classes, $href];
                    $used += mb_strwidth($chunk);
                }
            }
        }

        return $lines->push(static::trimmed($line))->map(fn (array $segments): string => collect($segments)
            ->map(fn (array $segment): string => static::segment(...$segment))
            ->implode(''))->all();
    }
}
