<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RecordShareImage
{
    public function renderHome(): string
    {
        $image = imagecreatetruecolor(1200, 630);
        $red = $this->color($image, 245, 68, 42);
        $ink = $this->color($image, 30, 30, 21);
        $acid = $this->color($image, 219, 238, 141);
        $display = public_path('fonts/barlow-condensed-bold.ttf');

        imagefill($image, 0, 0, $red);
        imagefilledrectangle($image, 0, 426, 1199, 629, $acid);

        imagettftext($image, 170, 0, 70, 218, $ink, $display, 'GOOD TASTE.');
        imagettftext($image, 100, 0, 70, 357, $ink, public_path('fonts/instrument-serif-italic.ttf'), 'is contagious.');
        imagettftext($image, 165, 0, 62, 594, $ink, $display, 'Plaat Club.');

        imagefilledellipse($image, 1058, 529, 154, 154, $ink);
        imageellipse($image, 1058, 529, 128, 128, $acid);
        imageellipse($image, 1058, 529, 108, 108, $acid);
        imagefilledellipse($image, 1058, 529, 50, 50, $red);
        imagefilledellipse($image, 1058, 529, 8, 8, $ink);

        return $this->toJpeg($image);
    }

    /** @param list<array<string, mixed>> $records */
    public function renderClub(string $title, string $subtitle, array $records = []): string
    {
        $image = imagecreatetruecolor(1200, 630);
        $red = $this->color($image, 245, 68, 42);
        $ink = $this->color($image, 30, 30, 21);
        $acid = $this->color($image, 219, 238, 141);
        $cream = $this->color($image, 255, 240, 207);
        $display = public_path('fonts/barlow-condensed-bold.ttf');
        $body = public_path('fonts/dm-sans-regular.ttf');
        imagefill($image, 0, 0, $red);
        imagefilledpolygon($image, [0, 440, 1200, 280, 1200, 630, 0, 630], $acid);
        imagettftext($image, 32, 0, 54, 82, $ink, $display, 'Plaat Club.');

        $size = $records === [] ? 100 : 70;
        $lines = $this->wrap($title, $display, $size, $records === [] ? 720 : 1090, 2);
        foreach ($lines as $index => $line) {
            imagettftext($image, $size, 0, 54, 205 + $index * ($size + 8), $ink, $display, $line);
        }

        if ($records === []) {
            imagefilledellipse($image, 969, 421, 340, 340, $ink);
            for ($diameter = 328; $diameter > 106; $diameter -= 12) {
                imageellipse($image, 969, 421, $diameter, $diameter, $this->color($image, 65, 65, 52));
            }
            imagefilledellipse($image, 969, 421, 98, 98, $red);
            imagefilledellipse($image, 969, 421, 10, 10, $cream);
            if ($subtitle !== '') {
                foreach ($this->wrap($subtitle, public_path('fonts/instrument-serif-italic.ttf'), 58, 720, 2) as $index => $line) {
                    imagettftext($image, 58, 0, 54, 390 + $index * 72, $ink, public_path('fonts/instrument-serif-italic.ttf'), $line);
                }
                imagettftext($image, 21, 0, 54, 578, $ink, $body, 'A listening circle for record collectors.');
            }

            return $this->toJpeg($image);
        }

        foreach ($records as $index => $record) {
            $left = 54 + $index * 220;
            imagefilledrectangle($image, $left + 6, 348, $left + 206, 548, $ink);
            imagefilledrectangle($image, $left, 342, $left + 200, 542, $cream);
            $cover = $this->cover($record['cover'] ?? '');
            if ($cover) {
                imagecopyresampled($image, $cover, $left, 342, 0, 0, 200, 200, imagesx($cover), imagesy($cover));
            }
        }
        if ($subtitle !== '') {
            imagettftext($image, 23, 0, 54, 597, $ink, $body, $subtitle);
        }

        return $this->toJpeg($image);
    }

    /** @param array<int, array<string, mixed>> $records */
    public function renderCollection(array $records): string
    {
        $image = $this->paperCanvas();
        $paper = $this->color($image, 232, 221, 196);
        $ink = $this->color($image, 38, 39, 32);
        $woodEdge = $this->color($image, 167, 122, 75);

        $wood = $this->asset(resource_path('images/walnut.jpg'));
        imagecopyresampled($image, $wood, 24, 284, 0, 0, 1152, 322, imagesx($wood), imagesy($wood));
        imagefilledrectangle($image, 36, 296, 1164, 591, $this->color($image, 15, 8, 3, 54));
        imageline($image, 24, 284, 1176, 284, $woodEdge);
        imageline($image, 36, 590, 1164, 590, $woodEdge);

        imagettftext($image, 68, 0, 44, 170, $ink, public_path('fonts/barlow-condensed-bold.ttf'), 'Plaat Club.');

        $stereo = $this->asset(resource_path('images/listening-corner.png'));
        imagecopyresampled($image, $stereo, 556, 65, 0, 0, 618, 206, imagesx($stereo), imagesy($stereo));

        $shadow = $this->color($image, 12, 7, 4, 35);

        foreach (array_values($records) as $index => $record) {
            $cover = $this->cover($record['cover']);

            if (! $cover) {
                continue;
            }

            $left = 53 + $index * 222;

            imagefilledrectangle($image, $left + 5, 336, $left + 216, 547, $shadow);
            imagefilledrectangle($image, $left - 2, 326, $left + 210, 538, $paper);
            imagecopyresampled($image, $cover, $left, 328, 0, 0, 208, 208, imagesx($cover), imagesy($cover));
        }

        return $this->toJpeg($image);
    }

    /** @param array<string, mixed> $record */
    public function render(array $record): string
    {
        $image = $this->paperCanvas();
        $paper = $this->color($image, 232, 221, 196);
        $ink = $this->color($image, 38, 39, 32);
        $accent = $this->color($image, 157, 53, 34);
        $groove = $this->color($image, 53, 54, 45);

        imagefilledellipse($image, 330, 315, 550, 550, $ink);

        for ($diameter = 540; $diameter > 180; $diameter -= 7) {
            imageellipse($image, 330, 315, $diameter, $diameter, $groove);
        }

        $cover = $this->cover($record['cover']);

        imagefilledellipse($image, 330, 315, 172, 172, $cover ? $this->labelColor($image, $cover) : $paper);
        imagefilledellipse($image, 330, 315, 10, 10, $ink);
        imagefilledrectangle($image, 46, 138, 482, 574, $this->color($image, 85, 70, 49, 65));
        imagefilledrectangle($image, 39, 131, 473, 565, $paper);

        if ($cover) {
            imagecopyresampled($image, $cover, 40, 132, 0, 0, 432, 432, imagesx($cover), imagesy($cover));
        }

        $display = public_path('fonts/barlow-condensed-bold.ttf');
        $body = public_path('fonts/plex-sans-regular.ttf');

        imagettftext($image, 42, 0, 642, 114, $ink, $display, 'Plaat Club.');
        imagefilledrectangle($image, 644, 137, 1144, 141, $accent);

        $artistLines = $this->wrap($record['artist'], $body, 38, 500, 2);
        [$titleSize, $titleLines] = $this->fitTitle($record['displayTitle'], $display, 316 - count($artistLines) * 52);
        $titleLeading = (int) round($titleSize * 1.12);
        $year = $record['originalYear'] ? (string) $record['originalYear'] : '';
        $yearGap = 72;
        $blockHeight = count($artistLines) * 52 + count($titleLines) * $titleLeading + ($year === '' ? 0 : $yearGap);
        $baseline = 220 + (int) ((380 - $blockHeight) / 2);

        foreach ($artistLines as $line) {
            imagettftext($image, 38, 0, 644, $baseline, $ink, $body, $line);

            $baseline += 52;
        }

        $baseline += $titleSize - 38;

        foreach ($titleLines as $line) {
            imagettftext($image, $titleSize, 0, 642, $baseline, $ink, $display, $line);

            $baseline += $titleLeading;
        }

        imagettftext($image, 34, 0, 644, $baseline - $titleLeading + $yearGap, $accent, $body, $year);

        return $this->toJpeg($image);
    }

    protected function paperCanvas(): GdImage
    {
        $image = imagecreatetruecolor(1200, 630);

        imagefill($image, 0, 0, $this->color($image, 232, 221, 196));

        $grain = $this->asset(public_path('images/paper-grain.png'));
        imagesettile($image, $grain);
        imagefilledrectangle($image, 0, 0, 1200, 630, IMG_COLOR_TILED);

        return $image;
    }

    protected function labelColor(GdImage $image, GdImage $cover): int
    {
        $sample = imagecolorat($cover, (int) (imagesx($cover) * .7), (int) (imagesy($cover) * .2));

        return $this->color($image, ($sample >> 16) & 255, ($sample >> 8) & 255, $sample & 255);
    }

    /** @return array{0: int, 1: array<int, string>} */
    protected function fitTitle(string $title, string $font, int $height): array
    {
        $size = 82;
        $lines = $this->wrap($title, $font, $size, 500);

        while ($size > 40 && count($lines) * round($size * 1.12) > $height) {
            $size -= 2;
            $lines = $this->wrap($title, $font, $size, 500);
        }

        return [$size, $this->wrap($title, $font, $size, 500, (int) floor($height / round($size * 1.12)))];
    }

    protected function toJpeg(GdImage $image): string
    {
        imageinterlace($image, true);

        ob_start();
        imagejpeg($image, null, 82);

        return ob_get_clean() ?: '';
    }

    /**
     * @param  int<0, 255>  $red
     * @param  int<0, 255>  $green
     * @param  int<0, 255>  $blue
     * @param  int<0, 127>  $alpha
     */
    protected function color(GdImage $image, int $red, int $green, int $blue, int $alpha = 0): int
    {
        $color = imagecolorallocatealpha($image, $red, $green, $blue, $alpha);

        if ($color === false) {
            throw new RuntimeException('Unable to allocate a social image color.');
        }

        return $color;
    }

    protected function asset(string $path): GdImage
    {
        $bytes = @file_get_contents($path);
        $image = $bytes ? @imagecreatefromstring($bytes) : false;

        if ($image === false) {
            throw new RuntimeException("Unable to load social image asset [{$path}].");
        }

        return $image;
    }

    protected function cover(string $path): ?GdImage
    {
        $bytes = $this->coverBytes($path);

        if (! $bytes) {
            return null;
        }

        return @imagecreatefromstring($bytes) ?: null;
    }

    protected function coverBytes(string $path): ?string
    {
        $disk = Storage::disk('public');
        $storedPrefix = rtrim($disk->url('records'), '/').'/';

        if (str_starts_with($path, $storedPrefix)) {
            $filename = substr($path, strlen($storedPrefix));

            if (! preg_match('/^[0-9]+\.jpg$/', $filename)) {
                return null;
            }

            return $disk->get("records/{$filename}");
        }

        if (RecordCoverImages::isBundledCover($path)) {
            return @file_get_contents(public_path(ltrim($path, '/'))) ?: null;
        }

        if (! RecordCoverImages::isDiscogsCover($path)) {
            return null;
        }

        try {
            $response = Http::connectTimeout(3)->timeout(5)->get($path);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /** @return array<int, string> */
    protected function wrap(string $text, string $font, int $size, int $width, int $maxLines = PHP_INT_MAX): array
    {
        $lines = [];
        $line = '';

        foreach (explode(' ', $text) as $word) {
            $candidate = trim("{$line} {$word}");

            if ($line !== '' && $this->textWidth($candidate, $font, $size) > $width) {
                $lines[] = $line;
                $line = '';
            }

            if ($this->textWidth($word, $font, $size) > $width) {
                foreach (mb_str_split($word) as $character) {
                    if ($this->textWidth($line.$character, $font, $size) > $width) {
                        $lines[] = $line;
                        $line = '';
                    }

                    $line .= $character;
                }

                continue;
            }

            $line = trim("{$line} {$word}");
        }

        $lines[] = $line;

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last = array_pop($lines);

            while ($this->textWidth($last.'…', $font, $size) > $width) {
                $last = mb_substr($last, 0, -1);
            }

            $lines[] = rtrim($last).'…';
        }

        return $lines;
    }

    protected function textWidth(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        if ($box === false) {
            throw new RuntimeException('Unable to measure social image text.');
        }

        return $box[2] - $box[0];
    }
}
