<?php

declare(strict_types=1);

namespace Laravel\Converse\Enums;

use Laravel\Converse\Contracts\Drivers\Driver;
use Laravel\Converse\Contracts\Drivers\SupportsAddReactions;
use Laravel\Converse\Contracts\Drivers\SupportsDeleteMessages;
use Laravel\Converse\Contracts\Drivers\SupportsDownloadAttachments;
use Laravel\Converse\Contracts\Drivers\SupportsEditMessages;
use Laravel\Converse\Contracts\Drivers\SupportsFindChannels;
use Laravel\Converse\Contracts\Drivers\SupportsFindUsers;
use Laravel\Converse\Contracts\Drivers\SupportsGetChannels;
use Laravel\Converse\Contracts\Drivers\SupportsGetUsers;
use Laravel\Converse\Contracts\Drivers\SupportsListMessages;
use Laravel\Converse\Contracts\Drivers\SupportsOpenDirectMessages;
use Laravel\Converse\Contracts\Drivers\SupportsRemoveReactions;
use Laravel\Converse\Contracts\Drivers\SupportsSendAttachments;
use Laravel\Converse\Contracts\Drivers\SupportsSendEphemeralMessages;
use Laravel\Converse\Contracts\Drivers\SupportsUpdateStatuses;

enum Capability: string
{
    case AddReaction = 'add-reaction';

    case DeleteMessage = 'delete-message';

    case DownloadAttachment = 'download-attachment';

    case EditMessage = 'edit-message';

    case FindChannel = 'find-channel';

    case FindUser = 'find-user';

    case GetChannel = 'get-channel';

    case GetUser = 'get-user';

    case ListMessages = 'list-messages';

    case OpenDirectMessage = 'open-direct-message';

    case RemoveReaction = 'remove-reaction';

    case SendAttachment = 'send-attachment';

    case SendEphemeralMessage = 'send-ephemeral-message';

    case UpdateStatus = 'update-status';

    /**
     * @return class-string<Driver>
     */
    public function contract(): string
    {
        return match ($this) {
            self::AddReaction => SupportsAddReactions::class,
            self::DeleteMessage => SupportsDeleteMessages::class,
            self::DownloadAttachment => SupportsDownloadAttachments::class,
            self::EditMessage => SupportsEditMessages::class,
            self::FindChannel => SupportsFindChannels::class,
            self::FindUser => SupportsFindUsers::class,
            self::GetChannel => SupportsGetChannels::class,
            self::GetUser => SupportsGetUsers::class,
            self::ListMessages => SupportsListMessages::class,
            self::OpenDirectMessage => SupportsOpenDirectMessages::class,
            self::RemoveReaction => SupportsRemoveReactions::class,
            self::SendAttachment => SupportsSendAttachments::class,
            self::SendEphemeralMessage => SupportsSendEphemeralMessages::class,
            self::UpdateStatus => SupportsUpdateStatuses::class,
        };
    }

    /**
     * @param  class-string<Driver>  $contract
     */
    public static function tryFromContract(string $contract): ?self
    {
        return array_find(self::cases(), fn (self $capability): bool => $capability->contract() === $contract);
    }

    public function supportedBy(Driver $driver): bool
    {
        return is_a($driver, $this->contract());
    }

    public function label(): string
    {
        return match ($this) {
            self::AddReaction => 'adding reactions',
            self::DeleteMessage => 'deleting messages',
            self::DownloadAttachment => 'receiving attachments',
            self::EditMessage => 'editing messages',
            self::FindChannel => 'channel lookups',
            self::FindUser => 'user lookups by name',
            self::GetChannel => 'channel metadata',
            self::GetUser => 'user lookups',
            self::ListMessages => 'message history',
            self::OpenDirectMessage => 'direct messages',
            self::RemoveReaction => 'removing reactions',
            self::SendAttachment => 'sending attachments',
            self::SendEphemeralMessage => 'ephemeral messages',
            self::UpdateStatus => 'typing indicators',
        };
    }
}
