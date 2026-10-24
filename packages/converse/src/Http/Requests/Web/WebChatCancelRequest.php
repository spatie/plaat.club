<?php

declare(strict_types=1);

namespace Laravel\Converse\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;
use Laravel\Converse\Http\Requests\Web\Concerns\InteractsWithWebChat;

class WebChatCancelRequest extends FormRequest
{
    use InteractsWithWebChat;

    public function runId(): string
    {
        return (string) $this->route('run');
    }
}
