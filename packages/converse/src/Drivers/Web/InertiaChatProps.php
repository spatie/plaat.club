<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Web;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Inertia\Inertia;
use Inertia\PropertyContext;
use Inertia\ProvidesInertiaProperty;
use Inertia\ScrollMetadata;
use Inertia\ScrollProp;
use Inertia\Support\Header;
use Laravel\Converse\Thread;

class InertiaChatProps extends ChatProps implements ProvidesInertiaProperty
{
    /**
     * @return array{threadId?: string, offset?: int, endpoint?: string, streamEndpoint?: string, messages: ScrollProp<mixed>, interrupts?: list<array<string, mixed>>, transcriptPath?: string, capabilities?: array<string, bool>}
     */
    public function toInertiaProperty(PropertyContext $prop): array
    {
        $pageName = str_replace('.', '_', $prop->key).'_cursor';
        $transcriptPath = "{$prop->key}.messages";
        $cursor = $this->requestedCursor($prop->request, $pageName, $transcriptPath);

        if ($cursor instanceof Cursor) {
            return $this->olderPage($cursor, $pageName);
        }

        return $this->payload($this->scroll($this->snapshot(), $pageName), $transcriptPath);
    }

    /**
     * @return array{messages: ScrollProp<mixed>}
     */
    protected function olderPage(Cursor $cursor, string $pageName): array
    {
        $page = $this->transcript->page($this->cursorThread($cursor), $this->perPage, $cursor);

        return ['messages' => $this->scroll($page, $pageName, $cursor)];
    }

    /**
     * @param  array{messages: list<array<string, mixed>>, nextCursor: Cursor|null}  $page
     * @return ScrollProp<mixed>
     */
    protected function scroll(array $page, string $pageName, ?Cursor $cursor = null): ScrollProp
    {
        return Inertia::scroll(
            fn (): array => ['data' => array_reverse($page['messages'])],
            metadata: new ScrollMetadata($pageName, null, $page['nextCursor']?->encode(), $cursor?->encode() ?? 1),
        );
    }

    protected function cursorThread(Cursor $cursor): Thread
    {
        $threadId = $cursor->toArray()['thread'] ?? null;

        if (! is_string($threadId) || preg_match('/^'.WebDriver::ID_PATTERN.'\z/', $threadId) !== 1) {
            return $this->thread;
        }

        return $this->thread->withId($threadId);
    }

    protected function requestedCursor(Request $request, string $pageName, string $transcriptPath): ?Cursor
    {
        $cursor = $request->input($pageName);

        if (! is_string($cursor) || ! $this->requestsOnly($request, $transcriptPath)) {
            return null;
        }

        return Cursor::fromEncoded($cursor);
    }

    protected function requestsOnly(Request $request, string $transcriptPath): bool
    {
        return in_array($transcriptPath, explode(',', (string) $request->header(Header::PARTIAL_ONLY)));
    }
}
