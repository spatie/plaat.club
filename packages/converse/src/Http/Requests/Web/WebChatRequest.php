<?php

declare(strict_types=1);

namespace Laravel\Converse\Http\Requests\Web;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use Laravel\Ai\AgentUserInteraction\AgentUserInteraction;
use Laravel\Ai\AgentUserInteraction\Chat;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Converse\Attachments\UploadPolicy;
use Laravel\Converse\Drivers\Web\WebDriver;
use Laravel\Converse\Http\Requests\Web\Concerns\InteractsWithWebChat;

class WebChatRequest extends FormRequest
{
    use InteractsWithWebChat;

    protected ?string $threadId = null;

    protected ?string $runId = null;

    protected ?Chat $chat = null;

    /**
     * @return array<string, array<int, string|Closure>>
     */
    public function rules(): array
    {
        $policy = UploadPolicy::fromConfig();

        return [
            'threadId' => ['nullable', 'string', 'regex:/^'.WebDriver::ID_PATTERN.'\z/'],
            'messages' => ['required_without:resume', 'array'],
            'messages.*' => ['array'],
            'messages.*.id' => ['nullable', 'string', 'regex:/^'.WebDriver::ID_PATTERN.'\z/'],
            'messages.*.role' => ['required', 'string'],
            'messages.*.content' => ['nullable', $this->attachmentCount($policy)],
            'messages.*.content.*' => ['array'],
            'messages.*.content.*.type' => ['required', 'in:text,image,audio,video,document'],
            'messages.*.content.*.text' => ['required_if:messages.*.content.*.type,text', 'string'],
            'messages.*.content.*.source' => ['required_unless:messages.*.content.*.type,text', 'array'],
            'messages.*.content.*.source.type' => ['required_unless:messages.*.content.*.type,text', 'in:url,data'],
            'messages.*.content.*.source.value' => ['required_unless:messages.*.content.*.type,text', 'string', 'max:'.intdiv($policy->maxBytes() + 2, 3) * 4],
            'messages.*.content.*.source.mimeType' => ['required_unless:messages.*.content.*.type,text', 'string', $this->allowedMimeType($policy)],
            'messages.*.content.*.metadata' => ['nullable', 'array'],
            'resume' => ['nullable', 'array'],
            'resume.*' => ['array'],
            'resume.*.interruptId' => ['required', 'string', 'max:255'],
            'resume.*.status' => ['nullable'],
            'resume.*.payload' => ['nullable'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && ! $this->chat()->message() instanceof UserMessage && ! $this->chat()->decisions() instanceof Decisions) {
                    $validator->errors()->add('messages', 'The turn has no user message.');
                }
            },
        ];
    }

    public function threadId(): string
    {
        return $this->threadId ??= $this->requestedThreadId() ?? (string) Str::uuid7();
    }

    protected function requestedThreadId(): ?string
    {
        if (! $this->filled('threadId')) {
            return null;
        }

        return $this->string('threadId')->toString();
    }

    public function runId(): string
    {
        return $this->runId ??= (string) Str::uuid7();
    }

    public function messageId(): ?string
    {
        return $this->safe()->collect('messages')->pluck('id')->last();
    }

    public function chat(): Chat
    {
        return $this->chat ??= AgentUserInteraction::chat($this->chatInput());
    }

    /**
     * @return array<string, mixed>
     */
    public function chatInput(): array
    {
        return [
            ...$this->safe()->only(['resume']),
            'messages' => array_slice(array_values($this->safe()->array('messages')), -1),
            'threadId' => $this->threadId(),
            'runId' => $this->runId(),
        ];
    }

    /**
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    protected function attachmentCount(UploadPolicy $policy): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($policy): void {
            $attachments = array_filter(is_array($value) ? $value : [], fn (mixed $part): bool => ! is_array($part) || ($part['type'] ?? null) !== 'text');

            if (count($attachments) > $policy->maxFiles()) {
                $fail('The turn may carry at most '.$policy->maxFiles().' '.Str::plural('attachment', $policy->maxFiles()).'.');
            }
        };
    }

    /**
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    protected function allowedMimeType(UploadPolicy $policy): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($policy): void {
            if (! $policy->allows(is_string($value) ? $value : null)) {
                $fail('The attachment type is not allowed.');
            }
        };
    }
}
