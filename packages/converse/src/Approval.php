<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
class Approval implements Arrayable, JsonSerializable
{
    public const string APPROVE_ACTION = 'converse-approve-tools';

    public const string REJECT_ACTION = 'converse-reject-tools';

    /** @var array<int, string> */
    protected const array APPROVING_REPLIES = ['yes', 'y', 'yep', 'yeah', 'ok', 'okay', 'approve', 'approved', 'confirm', 'go'];

    /** @var array<int, string> */
    protected const array REJECTING_REPLIES = ['no', 'n', 'nope', 'stop', 'cancel', 'deny', 'denied', 'reject', 'rejected'];

    protected const string LEADING_MENTIONS = '/^(?:\s*(?:<@[^>]+>|@[\w.\-\[\]]+))+/u';

    /** @var Collection<int, ApprovalTool> */
    protected Collection $tools;

    /**
     * @param  iterable<int, ApprovalTool>  $tools
     */
    public function __construct(protected string $token, iterable $tools)
    {
        $this->tools = Collection::make($tools)->values();
    }

    public function token(): string
    {
        return $this->token;
    }

    /**
     * @return Collection<int, ApprovalTool>
     */
    public function tools(): Collection
    {
        return $this->tools;
    }

    public function summary(): string
    {
        return 'Approval required';
    }

    /**
     * @return array<string, bool>
     */
    public function decisions(bool $approved): array
    {
        return array_fill_keys($this->tools->map->id()->all(), $approved);
    }

    /**
     * @internal
     *
     * @param  array<string, bool>  $decisions
     */
    public static function decisionSummary(array $decisions): string
    {
        $approved = array_filter($decisions);

        return match (count($approved)) {
            count($decisions) => 'Approved tool request',
            0 => 'Rejected tool request',
            default => 'Resolved tool request',
        };
    }

    /**
     * @internal
     *
     * @param  array<string, bool>  $decisions
     */
    public static function wasApproved(array $decisions): bool
    {
        return $decisions !== [] && ! in_array(false, $decisions, true);
    }

    /** @internal */
    public static function decisionFromReply(string $reply): ?bool
    {
        $reply = Str::lower(trim((string) preg_replace(static::LEADING_MENTIONS, '', $reply), " \t\n\r\0\x0B.!"));

        return match (true) {
            in_array($reply, static::APPROVING_REPLIES, true) => true,
            in_array($reply, static::REJECTING_REPLIES, true) => false,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'tools' => $this->tools->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
