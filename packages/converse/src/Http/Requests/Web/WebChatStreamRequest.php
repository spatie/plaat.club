<?php

declare(strict_types=1);

namespace Laravel\Converse\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;
use Laravel\Converse\Drivers\Web\WebChatOptions;
use Laravel\Converse\Enums\WebTransport;
use Laravel\Converse\Http\Requests\Web\Concerns\InteractsWithWebChat;

class WebChatStreamRequest extends FormRequest
{
    use InteractsWithWebChat;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'offset' => ['nullable', 'integer', 'min:0'],
            'follow' => ['nullable', 'boolean'],
        ];
    }

    public function offset(): int
    {
        return $this->integer('offset');
    }

    public function following(): bool
    {
        return $this->boolean('follow', true);
    }

    public function options(): WebChatOptions
    {
        $options = $this->driver()->options();
        $transport = $this->bot()->webTransport();

        if (! $transport instanceof WebTransport) {
            return $options;
        }

        return $options->withTransport($transport, $this->bot()->webIntervalSeconds());
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('offset') && $this->hasHeader('Last-Event-ID')) {
            $this->merge(['offset' => $this->header('Last-Event-ID')]);
        }
    }
}
