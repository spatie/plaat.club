# Laravel Converse for Vue

<p align="left">
<a href="https://github.com/laravel/converse/actions/workflows/tests.yml"><img src="https://github.com/laravel/converse/actions/workflows/tests.yml/badge.svg" alt="Build Status"></a>
<a href="https://www.npmjs.com/package/@laravel/converse-vue"><img src="https://img.shields.io/npm/dt/@laravel/converse-vue" alt="Total Downloads"></a>
<a href="https://www.npmjs.com/package/@laravel/converse-vue"><img src="https://img.shields.io/npm/v/@laravel/converse-vue" alt="Latest Stable Version"></a>
<a href="https://www.npmjs.com/package/@laravel/converse-vue"><img src="https://img.shields.io/npm/l/@laravel/converse-vue" alt="License"></a>
</p>

- [Introduction](#introduction)
- [Installation](#installation)
- [The `useChat` Composable](#the-usechat-composable)
- [Status](#status)
- [Messages](#messages)
- [Sending](#sending)
- [The Composer](#the-composer)
- [Anchoring The Transcript](#anchoring-the-transcript)
- [The Transport](#the-transport)
- [`<Chat>` Props](#chat-props)
- [License](#license)

## Introduction

This package provides the Vue components for the web chat of [Laravel Converse](https://github.com/laravel/converse).

**To add a chat to your application, follow the [web chat documentation](https://github.com/laravel/converse#web-chat).** It covers the server side, the `<Chat>` component, streaming, approvals, errors, and paging older messages.

This README documents the lower-level API that the `<Chat>` component is built on, for building your own chat component using the `useChat` composable.

## Installation

```bash
npm install @laravel/converse-vue
```

Requires Vue 3.5 or later.

## The `useChat` Composable

The `<Chat>` component is `useChat` plus the composer and the transcript anchor, already wired together. You may use the composable directly when you want to own that wiring:

```vue
<script setup lang="ts">
import { useChat } from "@laravel/converse-vue";
import type { ChatPayload } from "@laravel/converse-vue";

const props = defineProps<{ chat: ChatPayload }>();

const { messages, send, status } = useChat(() => props.chat);
</script>
```

You should pass a getter when the payload comes from props. Partial reloads and prepended pages of older messages will then update the transcript without remounting the component.

The second argument given to `useChat` is an options object:

```ts
type UseChatOptions = {
    connection?: MaybeRef<ChatConnection | undefined>;
    history?: MaybeRefOrGetter<ChatMessage[] | undefined>;
    queue?: MaybeRefOrGetter<"queue" | "drop" | "interrupt" | undefined>;
    headers?: MaybeRefOrGetter<Record<string, string> | undefined>;
    csrfToken?: MaybeRefOrGetter<string | undefined>;
    credentials?: MaybeRefOrGetter<RequestCredentials | undefined>;
    onEvent?: (event: AgUiEvent) => void;
    onFinish?: (message: ChatMessage) => void;
    onError?: (error: Error) => void;
    onSessionChange?: (session: ChatSession | null) => void;
};
```

Every option except the callbacks may be a plain value, a ref, or a getter, while `connection` may be a value or a ref. The `history` option supplies the transcript when it does not come from the payload. While the option is absent, the chat falls back to the payload's transcript.

The `onEvent` callback receives every AG-UI event as it arrives, the `onFinish` callback receives the completed message, and the `onError` callback receives anything the transport threw.

The `queue` option is read each time a message arrives while the thread is busy, so a ref you change applies to the next one. The transport options (`connection`, `headers`, `csrfToken`, and `credentials`) are read at the start of every request, so a ref you change reaches the next turn.

## Status

The `status` value is one of the following:

| Status      | Meaning                                                      |
| ----------- | ------------------------------------------------------------ |
| `ready`     | Nothing in flight                                            |
| `submitted` | Sent, waiting for the first event                            |
| `streaming` | Events arriving                                              |
| `resuming`  | Rejoined a run already in flight, replaying the events it already wrote |
| `error`     | The last request failed                                      |

A separate `streaming` boolean is also returned. The `can` object exposes what the thread supports: `can.approve`, `can.stream`, and `can.retry`. A handler bot neither pauses for approval nor replays a failed turn, so `can.approve` and `can.retry` are `false` for it.

## Messages

Each message's `content` property holds the answer as one string. The `parts` property holds the same answer as an ordered list. For a turn that uses tools, reasoning, or search, you may render the parts instead, so each piece appears where it arrived:

```vue
<template>
    <article v-for="message in messages" :key="message.id">
        <template v-for="(part, index) in message.parts" :key="index">
            <div
                v-if="part.type === 'text'"
                v-html="renderStreamingMarkdown(part.text)"
            />

            <details v-else-if="part.type === 'reasoning'">
                <summary>Thinking</summary>
                <div v-html="renderStreamingMarkdown(part.text)" />
            </details>

            <div v-else-if="part.type === 'dynamic-tool'">
                {{ part.toolName }}: {{ part.state }}
                <p v-if="part.errorText">{{ part.errorText }}</p>
                <p v-else-if="part.output">{{ part.output }}</p>
            </div>

            <a v-else-if="part.type === 'source-url'" :href="part.url">
                {{ part.title ?? part.url }}
            </a>
        </template>
    </article>
</template>
```

A part is added when its content arrives, so a message that is still thinking has none at all. The [Laravel Converse documentation](https://github.com/laravel/converse#rendering-the-chat) covers the `isAwaitingContent` and `renderStreamingMarkdown` helpers. The `reasoning` and `citations` properties remain on the message as flattened conveniences, but they do not express ordering.

Each message also carries its `status`, the `error` of a turn that failed, the `provider` and `model` that answered, its token `usage`, and `createdAt`. Besides the parts above, a part may be a `file` or a `data-*` extension part. A provider's own tools, such as web search, arrive as `data-provider-tool` parts, so you may show the search next to the answer it informed.

A message sent from the browser is rendered immediately. Once the server stores it, the message takes its stored ID and keeps the local one as `clientMessageId`.

## Sending

The [Laravel Converse documentation](https://github.com/laravel/converse#sending-messages-from-the-browser) covers the `send` and `cancel` methods and the `queue` modes. Per-page settings, such as a persona or a model, belong in the server route that renders `Converse::chat`, since a turn carries nothing but the message.

The `queue` mode also applies to a message the server refuses because another tab's run holds the thread. Depending on the mode, the chat sends the message once that run finishes, drops it, or stops that run first and then sends it. A dropped message returns to the composer's draft, so it is never lost silently.

## The Composer

The `useChatComposer` composable holds the input draft and submits it through the chat store:

```vue
<script setup lang="ts">
import { useChat, useChatComposer } from "@laravel/converse-vue";

const chat = useChat(() => props.chat);
const { draft, submit } = useChatComposer(chat.store);
</script>

<template>
    <form @submit.prevent="submit()">
        <input v-model="draft" />
    </form>
</template>
```

Submitting clears the draft automatically. The composable also returns the `clear` method, which empties the draft without sending it.

## Anchoring The Transcript

The `useTranscriptScroll` composable anchors the newest prompt near the top of the transcript while the answer grows:

```vue
<script setup lang="ts">
import { useTranscriptScroll } from "@laravel/converse-vue";

const { container, spacerStyle, beginTurn } = useTranscriptScroll();
</script>

<template>
    <div ref="container" class="overflow-y-auto">
        <!-- messages -->
        <div :style="spacerStyle" />
    </div>
</template>
```

Bind `container` to the scrollable element and render `spacerStyle` on an empty element placed last inside it, then call `beginTurn` right after sending. The anchor is released once the user scrolls. The `<Chat>` component does this for you unless you pass `:anchor-prompts="false"`.

The composable anchors the prompt at the distance set by the container's `scroll-padding-top` CSS property. Without the property, the composable anchors the prompt 24 pixels below the top of the container. You may pass an `offset` in pixels to override the property:

```ts
useTranscriptScroll({ offset: 48 });
```

## The Transport

By default, each message is sent as a JSON `POST` request to the payload's `endpoint`, which claims a run for the thread. While a run is live, the chat reads the thread's events as server-sent events from the payload's `streamEndpoint`, whichever tab or device sent the turn. A dropped connection resumes from the last event it applied. The CSRF token is read from a `csrf-token` meta tag or Laravel's `XSRF-TOKEN` cookie.

A reloaded page follows the thread from the payload's `offset`, so an answer that was still arriving continues without replaying what the page already shows. The `status` value is `resuming` during that catch-up, and the `onSessionChange` callback reports the thread and offset the chat has read so far.

You may send the request yourself by passing a `connection`:

```ts
useChat(source, {
    connection: async ({ body, signal, onEvent }) => {
        // Call `onEvent` with each AG-UI event as it arrives.
    },
});
```

A connection replaces the transport, not the URL, since the endpoint already comes from the payload. This way, you may drive a turn over a WebSocket, or with no server at all. A chat given its own `connection` does not read the `headers`, `csrfToken`, or `credentials` options, so pass those to the connection instead.

## `<Chat>` Props

The `<Chat>` component takes the chat payload and hands you the thread in its default slot. Every option `useChat` accepts, except `onSessionChange`, is also a prop, so a page rarely needs to reach for the composable just to pass a single callback:

```ts
type ChatProps = {
    chat: ChatSource;

    // Forwarded to `useChat`
    connection?: ChatConnection;
    history?: ChatMessage[];
    queue?: "queue" | "drop" | "interrupt";
    headers?: Record<string, string>;
    csrfToken?: string;
    credentials?: RequestCredentials;
    onEvent?: (event: AgUiEvent) => void;
    onFinish?: (message: ChatMessage) => void;
    onError?: (error: Error) => void;

    // The component's own
    anchorPrompts?: boolean;
    anchorOffset?: number;
    hasPrevious?: boolean;
    loadPrevious?: () => void;
};
```

The `hasPrevious` and `loadPrevious` props are passed straight through to the slot, for a page driving pagination itself rather than nesting `<InfiniteScroll>`. The `anchorPrompts` and `anchorOffset` props are covered in the [Laravel Converse documentation](https://github.com/laravel/converse#scrolling-the-transcript).

A template ref on `<Chat>` receives the same values as the slot, already unwrapped, so you may use them from your script.

## License

Laravel Converse is open-sourced software licensed under the [MIT license](LICENSE.md).
