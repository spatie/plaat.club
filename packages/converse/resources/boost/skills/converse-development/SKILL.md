---
name: converse-development
description: Laravel Converse bot development and debugging for Slack, Telegram, or GitHub. Use for event routing, outbound messages and interactions, thread state and concurrency, Laravel AI bot integration, connection and webhook setup, custom drivers, or bot tests. Also use when a Converse bot has webhook failures, duplicate burst replies, lost state, or handler and agent routing surprises, and for the server side of Converse's optional web chat (Converse::chat, converse:install, queued web turns) when the app serves a chat in its own pages. For the Vue or React chat page itself, use converse-vue or converse-react; for standalone Laravel AI, MCP, or direct platform SDK work, use the individual integration's guidance instead.
license: MIT
metadata:
  author: laravel
---

# Laravel Converse Development

## Workflow

### 1. Confirm the installed API

Locate `vendor/laravel/converse/README.md`, or this package's `README.md` when working in its repository. Read every section the change touches before writing code:

- Registration or routing: **Defining Bots**, **Middleware**, **Handlers** (with **Creating Handlers** and **Bot Events**), and **Combining Agents and Handlers**.
- Sending or state: **Threads** (with **Sending Messages**, **Thread Metadata**, **Message History**, **Channels and Direct Messages**, and **Capabilities**), **Cards**, **Attachments**, **Batched Messages**, and **Debouncing Messages**.
- Agents: **AI Agents**, including **Agent Bots**, **Agent Tools**, **Tool Approvals**, **Scheduled Turns**, and **Prompting Agents**.
- Credentials or delivery: **Configuration** (with **Webhooks**, **Queues**, and **Database and Cache**), **Platforms** (with **Slack**, **Telegram**, and **GitHub**), and **Pruning Threads**.
- Optional browser chat, only when the app serves a chat in its own pages: **Web Chat**, including **Identifying Web Users** and **Allowing Guests**. Page and component work belongs to the `converse-vue` or `converse-react` skill.
- Tests or extensions: **Testing**, **Chatting From the Terminal**, or **Custom Drivers**.

The README is the primary application API. Package archives exclude tests and the workbench, so consumer guidance must be self-contained or point to the README and shipped source. For package changes, or when observed behavior disagrees with the README, verify the contract in source and tests. Begin implementation when every class, method, config key, and event property you plan to use is confirmed in the installed version.

### 2. Assign one routing owner

Choose the entry point before editing:

- Application logic owns replies through `Converse::bot()` and handler contracts. A classic bot may split distinct routing slots across several handler classes.
- An LLM owns replies through `Converse::agent()`. Attach one handler with `handler()` for commands, actions, reactions, updates, deletions, or message cases that need deterministic handling. Converse registers only the default tools a thread can use. A tool may implement `shouldRegister(Thread $thread): bool` to choose the threads it registers for.
- Code outside an inbound event injects `BotRegistrar` and resolves a bot, channel, thread, or direct-message target.
- A new platform extends Laravel\Converse\Drivers\Driver, implements `verify`, `events`, and `sendMessage`, and adds only the capability contracts it supports. Register it with Converse::extend(), configure its connection, and bind the bot to that connection.

Message handlers match from narrowest to broadest: direct message, subscribed message, mention, then catch-all message. On agent bots, a matching specialized handler wins; `HandlesMessages` claims every otherwise unmatched message, while generic event and fallback handlers run only if neither a specialized handler nor the agent claims it.

This step is complete when each affected event category has one unambiguous owner and every bot is bound to every connection named by its events or outbound calls.

### 3. Preserve thread and platform semantics

A connection is a named credential set using a driver; there is no default connection. Treat bot, connection, channel, and thread as the durable conversation boundary. Botless package integrations use shared state, and bot-scoped meta or subscription reads may inherit that state, so mix the two access patterns only deliberately.

Use portable content first: plain text, `Markdown`, then `Card`. In interactive cards, use the action ID for routing intent and the value for the domain payload; give every card a meaningful fallback and every local action ID a `HandlesActions` branch. Hold the `SentMessage` returned by `send()` when later editing, deleting, or reacting to it. Check `supports(Capability::...)` before optional operations because unsupported behavior varies by capability.

This step is complete when identifiers preserve the intended conversation boundary and every bound platform either supports each operation or follows an explicit capability branch.

### 4. Account for asynchronous turns

Webhook work belongs on an asynchronous queue. The webhook only acknowledges the platform and dispatches `HandleInboundEvent`, so a deployed bot answers nothing unless a queue worker runs. When a bot appears to ignore messages, check the worker first. `php artisan converse:chat` runs on the `sync` connection and needs no worker. Keep `processing.timeout` below `processing.lock_ttl`, and both below the queue connection's `retry_after`.

Only `Message` events are collected into turns. The newest message drives the turn and earlier messages are available in `$message->others()`, or the whole turn in `$message->batch()`; consume them or deliberately tell the user only the latest message was handled. For burst behavior, inspect the effective global `processing.debounce` or bot `->debounce()` value and test a representative burst. Actions, reactions, commands, updates, and deletions serialize under the same thread lock but are not batched. Agent response text is buffered during steering, but tool side effects that already ran are not rolled back.

The default database conversation store requires the published `converse-migrations`. Coordination requires an atomic-lock cache; multiple workers or nodes require a shared cache. Action callbacks additionally need persistent cache entries. Queued retries may repeat handler, tool, and callback side effects, so make externally visible effects idempotent where they can be re-executed.

This step is complete when a representative burst produces the intended turns, retries preserve idempotent externally visible effects, and the selected storage and cache topology retain required context.

### 5. Wire the optional web connection

Skip this step unless the app serves a chat in its own pages; Slack, Telegram, and GitHub bots never need it. `php artisan converse:install` publishes the config, `routes/bot.php`, and migrations, registers an `assistant` agent bot on the `web` connection, and in an Inertia Vue or React app publishes a chat page and adds the `/chat` routes. Binding a bot to a `web` connection registers `converse.{connection}.send`, `.stream`, and `.cancel` routes under `/converse/{bot}/{connection}`, wrapped in the connection's `middleware`. Converse refuses any request without a resolved user unless the connection's `guests` option or the bot's `allowGuests()` allows guests, so that middleware needs only `web`. A guest chat also needs `auth` and `verified` removed from the page route. When the chat's users sign in on another guard, such as `admin`, set the connection's `guard` so the page and the chat's requests both read the user from it. Put the page route behind the same guard, such as `auth:admin`. A custom `resolveUsersUsing` callback replaces the `guard` option, so read the guard explicitly there. The page renders `Converse::chat($bot, new: ...)`, which continues the resolved user's or guest session's latest conversation; with Inertia 3 its transcript is a scroll prop paged newest first (`->perPage(20)`, 50 by default), and without Inertia it serializes to a plain array of the newest page. Start a new one with a `POST /chat/new` route that redirects back with a `new_conversation` session flash, read as `new: $request->session()->has('new_conversation')`, never a query parameter; the page's button posts with Inertia's `<Form>` and `reset: ['chat.messages']`, so the paged transcript starts over.

A web turn is a JSON `POST` that claims a run for the thread and answers `202` with `threadId`, `runId`, and `offset`. The turn runs server-side and writes AG-UI events to the thread's stream, which the page follows from that offset. A `409` means the thread is already answering, the message ID was already answered, or approval decisions no longer match (`approval_mismatch`); a `422` means nothing on the bot answers the message. An agent on a web connection must use `RemembersConversations`, or the first web turn throws `InvalidBotException`. A web agent gets none of Converse's default tools, so it needs no `withoutTools()` call.

Build a per-user agent from the resolver closure's `$participant`, the user the web chat belongs to: `Converse::agent('assistant', fn (User $participant) => new Assistant($participant))`. Pass that user into the constructors of the agent and its tools. Give each role only the tools it may call, rather than checking the role inside a tool. Never call `auth()->user()` in an agent or tool: Converse does not sign anyone in while a turn runs, so it returns `null` on a queue worker. The `$participant` argument is `null` when a turn has no user, such as a guest's turn or one on Slack and Telegram, so type it `?User` unless every turn the bot answers has a user.

Web turns run on the Converse queue by default, which needs a queue worker and a `retry_after` above `run_timeout`. When the connection's `queue` option is `false`, or the queue connection uses the `sync` driver, turns run after the response is sent and need no worker, which requires PHP-FPM rather than `php artisan serve`. While a run is live the stream pings every `stream_heartbeat` seconds so a closed tab frees its worker; `0` disables it. A stream holds a PHP worker while its run is live; when workers are scarce, set the connection's `transport` to `poll`, or call `->poll()` on the bot, so the browser reads new events every `poll_interval` seconds and no request waits on a run. Threads and web run events are kept for `storage.retention` days and only pruned when the application schedules `model:prune` with `--model` naming `ThreadEvent` and `Thread`.

This step is complete, for apps with a web chat, when the page route signs users in on the connection's `guard`, each user's agent has only the tools that user may call, the `queue` option and the `transport` match the deployment's workers, and pruning is scheduled.

### 6. Verify at the correct boundary

Try a bot by hand in the terminal with `php artisan converse:chat {bot}`. It needs no Slack, Telegram, or GitHub application, no deployment, and no queue worker. Slack, Telegram, and GitHub deliver webhooks over the public internet, so connect a real platform only from a deployed application, such as one on Laravel Cloud with a database and a queue worker.

Use `Converse::fake()`, register the bot and connection, dispatch a normalized event with `Converse::incoming()`, and assert outbound behavior. For an interactive card, assert its fallback or controls, then dispatch an `Action` for each routing branch and assert the result. String assertions are substring matches; use closures for exact content or thread checks.

When the app has a web chat, test its turns through HTTP: act as a user, fake the agent, `postJson` to `/converse/{bot}/web` with `threadId` and `messages: [['id' => ..., 'role' => 'user', 'content' => ...]]`, assert `202`, then read the stream at `/converse/{bot}/web/{thread}/stream?offset=...&follow=0` or assert the transcript in `Converse::chat($bot)->toArray()['messages']`. When the `web` connection's `queue` option is `false` or the queue connection uses the `sync` driver, the turn runs after the response, so it has run once the test request returns; otherwise the turn is queued, so call `Queue::fake()` before the first request and run the pushed `RunWebTurn` jobs.

The fake bypasses webhook verification and platform normalization, and it advertises capabilities a real platform may lack. Test route responses, signatures, payload parsing, and custom driver behavior through the HTTP or driver boundary. Test unsupported operations with the real driver or a minimal test driver that omits the capability contract. Run the focused test first, then `composer test` in this package or the consuming application's full test suite.

Verification is complete when every changed routing branch, platform fallback, and any web response status has an observable test at the boundary that owns the behavior.
