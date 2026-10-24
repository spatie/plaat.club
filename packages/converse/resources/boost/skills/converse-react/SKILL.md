---
name: converse-react
description: Builds the React chat page for Laravel Converse's optional web chat with @laravel/converse-react, typically in an Inertia React app. Use only when the app serves a Converse chat in its own pages and its frontend is React, such as creating or changing a page that receives a Converse::chat() payload; using the <Chat> render-prop component, useChat, useChatComposer, or useTranscriptScroll; rendering message parts, reasoning, tool calls, approvals, retry, cancel, queued messages, or chat errors; syncing other tabs; adding a "New conversation" button; or linking @laravel/converse-react from a local checkout. Do not use for Slack, Telegram, or GitHub bots, for server-side bots, routes, or the web connection (converse-development), for Vue pages (converse-vue), or for React chat interfaces that do not talk to Converse.
license: MIT
metadata:
  author: laravel
---

# Laravel Converse for React

## Workflow

### 1. Confirm the installed API

Read `node_modules/@laravel/converse-react/README.md`, or `packages/react/README.md` in the Converse repository, for every section the change touches. Its exports and types live in `dist/index.d.ts`. Use only names confirmed there; this is an early preview and names change without deprecation. Server-side work (the payload route, the web connection, queued web turns) belongs to the `converse-development` skill. These instructions assume an Inertia page; without Inertia, mount the same `<Chat>` with the `Converse::chat()` payload passed in however the page receives server data, and do not expect `converse:install` to publish a page for it.

This step is complete when every component prop, render prop value, hook, and type you plan to use is confirmed in the installed version.

### 2. Render with `<Chat>` by default

Pass the `Converse::chat()` payload, typed `ChatPayload`, to `<Chat chat={chat}>` and render everything from its child function, which receives `ChatRenderProps`: `messages`, `status`, `streaming`, `error`, `errorCode`, `validationErrors`, `pendingApprovals`, `queued`, `can`, `send`, `respond`, `approve`, `reject`, `retry`, `cancel`, `sync`, `clearError`, `composer`, `isAwaitingContent`, and `infiniteScrollProps`. Pass options as props: `queue` (read each time a message arrives busy, so it can change at runtime), `history`, `headers`, `csrfToken`, `credentials`, `connection`, `onEvent`, `onFinish`, `onError`, and `anchorPrompts={false}` to stop anchoring each prompt. Callbacks are read from the latest render, so inline functions are fine.

The `composer` holds the input: `value={composer.draft}` with `onChange={(event) => composer.setDraft(event.target.value)}`, `composer.submit()` from the form's `onSubmit` after `preventDefault()`, `ref={composer.container}` on the scrollable transcript, and an empty last child with `style={composer.spacerStyle}`. Mark each user prompt element with `{...{ [promptAttribute]: '' }}` so the anchor can find it.

Wrap the transcript in Inertia 3's `<InfiniteScroll {...infiniteScrollProps}>` whenever `infiniteScrollProps` is not null, so older pages of the transcript load as the user scrolls up; it is null without Inertia, where the payload holds only the newest page.

The render prop is not a component, so do not call hooks inside it; extract a child component that receives the values it needs. Reach for `useChat(chat, options)` at the top of a component only when chat state is needed outside the render prop. It accepts the payload or a getter, returns the same values plus `store`, which `useChatComposer(thread.store)` and `useTranscriptScroll({ offset })` wire up by hand, and is the only place that accepts `onSessionChange`.

This step is complete when the page renders from one chat instance and no state is duplicated outside it.

### 3. Render messages and turn controls

Render `message.parts` in order: `text` and `reasoning` carry `text`; `dynamic-tool` carries `toolName`, `state`, `output`, and `errorText`; `source-url` carries `url` and `title`. Show a placeholder while `isAwaitingContent(message)` is true. Use `renderStreamingMarkdown()` with `dangerouslySetInnerHTML` for partial markdown; it escapes its input, and the server never sends HTML.

- Approvals: render `message.approvals` (`id`, `tool`, `reason`, `arguments`) on the message that asked, behind `can.approve`, with `approve(id)` and `reject(id)`, or `respond({ [id]: { action } })` for several. Use `pendingApprovals` for a banner or to disable the composer.
- Retry: show `retry()` only while `can.retry` is true. The server grants it to agent bots only.
- Cancel: `cancel()` stops whichever run is answering; show it while `streaming`.
- Queue: `queue` is `queue` (default), `drop` (`send` resolves `false`), or `interrupt` (stops the run, then sends). `queued` lists waiting messages, which a reload loses.
- Errors: show `error`, branch on `errorCode` (such as `approval_mismatch`), read `validationErrors` from a 422, and offer `retry()` or `clearError()`.

This step is complete when every part type, approval, and error the bot can produce has a visible state.

### 4. Integrate with the page

A new conversation is a server concern: render Inertia's `<Form action="/chat/new" method="post" options={{ reset: ['chat.messages'] }}>` from `@inertiajs/react` with a submit button, as the scaffolded page does, whose route flashes `new_conversation` and redirects back, rather than adding a query parameter. Never use `<Link>` for it: a link cannot reset the paged transcript, so the new conversation would load the previous one's older messages. To pick up turns sent from another tab or device, call `sync()` from an effect on an interval, on window focus, or from an Echo listener, and clean the subscription up; overlapping calls reuse one read.

The component is SSR safe: the store only reads the thread from an effect in the browser, so do not touch `window` or start a read during render.

To link a local checkout, add both `@laravel/converse-react@link:../converse/packages/react` and `@laravel/converse@link:../converse/packages/converse`, add `resolve: { dedupe: ['react', 'react-dom'] }` to `vite.config.ts`, and point TypeScript at the application's types in `tsconfig.json` `paths`: `"react": ["./node_modules/@types/react"]` and `"react/*": ["./node_modules/@types/react/*"]`.

This step is complete when the page loads with SSR, a reload resumes an answer in progress, and a second tab catches up through `sync()`.

### 5. Verify in the browser

Build the page, send a message, reload mid-answer, and exercise each control the bot supports (approve, reject, cancel, retry, a message sent while busy). Run the application's frontend type check and linters, and its PHP tests for the page route.

Verification is complete when each control changes the transcript as expected and the type check passes.
