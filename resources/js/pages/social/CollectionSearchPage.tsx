import { Head, Link, usePage } from '@inertiajs/react';
import { useChat, type ChatPayload } from '@laravel/converse-react';
import { ArrowUp, LoaderCircle, Plus, X } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

import type { SocialProps } from '@/modules/social/types';
import { CollectionAnswer } from '@/modules/social/CollectionAnswer';
import '../../../css/collection-search.css';

export function CollectionSearchPage({
    chat,
    initialQuestion,
    reportedQuestionIds,
}: {
    chat: ChatPayload;
    initialQuestion: string;
    reportedQuestionIds: string[];
}) {
    const { csrfToken } = usePage<SocialProps>().props;
    const { messages, send, status, error, cancel, clearError } = useChat(
        chat,
        {
            csrfToken,
            queue: 'drop',
        },
    );
    const [question, setQuestion] = useState(initialQuestion);
    const [helpOpen, setHelpOpen] = useState(false);
    const input = useRef<HTMLTextAreaElement>(null);
    const composer = useRef<HTMLDivElement>(null);
    const help = useRef<HTMLDivElement>(null);
    const latestMessage = useRef<HTMLElement>(null);
    const pendingMessage = useRef<HTMLElement>(null);
    const awaitingAnswerAfter = useRef<number | null>(0);
    const busy = ['submitted', 'streaming', 'resuming'].includes(status);
    const lastMessage = messages.at(-1);
    const lastQuestion = messages.findLast(
        (message) => message.role === 'user',
    )?.content;

    useEffect(() => {
        const viewport = window.visualViewport;
        const element = composer.current;
        if (!viewport || !element) return;

        const updateKeyboardInset = () => {
            const inset =
                document.activeElement === input.current && viewport.scale === 1
                    ? Math.max(
                          0,
                          window.innerHeight -
                              viewport.height -
                              viewport.offsetTop,
                      )
                    : 0;
            element.style.setProperty('--ask-keyboard-inset', `${inset}px`);
        };

        viewport.addEventListener('resize', updateKeyboardInset);
        viewport.addEventListener('scroll', updateKeyboardInset);
        element.addEventListener('focusin', updateKeyboardInset);
        element.addEventListener('focusout', updateKeyboardInset);
        return () => {
            viewport.removeEventListener('resize', updateKeyboardInset);
            viewport.removeEventListener('scroll', updateKeyboardInset);
            element.removeEventListener('focusin', updateKeyboardInset);
            element.removeEventListener('focusout', updateKeyboardInset);
        };
    }, []);

    useEffect(() => {
        if (error) {
            awaitingAnswerAfter.current = null;
            return;
        }
        if (busy) {
            pendingMessage.current?.scrollIntoView({ block: 'nearest' });
            return;
        }
        if (
            awaitingAnswerAfter.current === null ||
            messages.length <= awaitingAnswerAfter.current ||
            lastMessage?.role !== 'assistant'
        )
            return;
        awaitingAnswerAfter.current = null;
        latestMessage.current?.scrollIntoView({
            block: 'nearest',
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                .matches
                ? 'instant'
                : 'smooth',
        });
    }, [busy, messages.length, lastMessage?.role, error]);

    useEffect(() => {
        if (!helpOpen) return;
        function closeOnOutsideClick(event: PointerEvent) {
            if (
                event.target instanceof Node &&
                !help.current?.contains(event.target)
            ) {
                setHelpOpen(false);
            }
        }
        function closeOnEscape(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                event.preventDefault();
                help.current?.querySelector('button')?.focus();
                setHelpOpen(false);
            }
        }
        document.addEventListener('pointerdown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);
        return () => {
            document.removeEventListener('pointerdown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [helpOpen]);

    async function ask(event: FormEvent) {
        event.preventDefault();
        if (busy || !question.trim()) return;
        const draft = question;
        awaitingAnswerAfter.current = messages.length;
        setQuestion('');
        if (!(await send(draft))) {
            awaitingAnswerAfter.current = null;
            setQuestion(draft);
        }
        input.current?.focus({ preventScroll: true });
    }

    async function reply(text: string) {
        if (busy) return;
        awaitingAnswerAfter.current = messages.length;
        if (!(await send(text))) awaitingAnswerAfter.current = null;
        input.current?.focus({ preventScroll: true });
    }

    return (
        <>
            <Head title="Ask your collection" />
            <div className="ask-page">
                <header className="ask-heading">
                    <h1>Ask your collection</h1>
                    <div
                        ref={help}
                        className="ask-help"
                        onPointerEnter={(event) => {
                            if (event.pointerType === 'mouse')
                                setHelpOpen(true);
                        }}
                        onPointerLeave={(event) => {
                            if (
                                !event.currentTarget.contains(
                                    document.activeElement,
                                )
                            )
                                setHelpOpen(false);
                        }}
                        onFocusCapture={() => setHelpOpen(true)}
                        onBlurCapture={(event) => {
                            if (
                                !event.currentTarget.contains(
                                    event.relatedTarget,
                                )
                            )
                                setHelpOpen(false);
                        }}
                    >
                        <button
                            type="button"
                            className="ask-help-toggle"
                            aria-label="Help with collection questions"
                            aria-expanded={helpOpen}
                            aria-controls="collection-help"
                            onClick={() => setHelpOpen(true)}
                        >
                            ?
                        </button>
                        {helpOpen && (
                            <div
                                id="collection-help"
                                className="ask-help-panel"
                                role="region"
                                aria-label="Collection help"
                            >
                                <div className="ask-help-heading">
                                    <strong>A few things to try</strong>
                                    <button
                                        type="button"
                                        aria-label="Close help"
                                        onClick={() => {
                                            help.current
                                                ?.querySelector('button')
                                                ?.focus();
                                            setHelpOpen(false);
                                        }}
                                    >
                                        <X size={18} aria-hidden="true" />
                                    </button>
                                </div>
                                <p>
                                    Search your collection, compare with a
                                    username or full name, or manage your lists.
                                    Follow up with “only vinyl” or “from the
                                    nineties”. New conversation starts fresh.
                                </p>
                                <div
                                    className="ask-examples"
                                    aria-label="Example questions"
                                >
                                    {[
                                        'Which records did Brian Eno produce?',
                                        'Show my lists',
                                        'Compare my collection with a friend',
                                    ].map((example) => (
                                        <button
                                            key={example}
                                            type="button"
                                            disabled={busy}
                                            onClick={() => {
                                                setQuestion(example);
                                                input.current?.focus();
                                            }}
                                        >
                                            {example}
                                        </button>
                                    ))}
                                </div>
                                <p>
                                    Recent conversation and relevant collection
                                    and list results go to OpenAI, never your
                                    notes. Chats are saved here. Suggestions
                                    aren’t audio analysis. List changes need
                                    confirmation.
                                </p>
                            </div>
                        )}
                    </div>
                </header>
                {!messages.length && !busy && (
                    <div className="ask-welcome">
                        <p>What would you like to find?</p>
                        <span>
                            Search your records, compare collections, or make a
                            list.
                        </span>
                    </div>
                )}
                {(!!messages.length || busy) && (
                    <section
                        className="ask-conversation"
                        aria-label="Conversation"
                    >
                        {messages.map((message, index) => (
                            <article
                                key={message.id}
                                ref={
                                    index === messages.length - 1
                                        ? latestMessage
                                        : undefined
                                }
                                className={`ask-message ask-message-${message.role}`}
                            >
                                <p className="sr-only">
                                    {message.role === 'user'
                                        ? 'You asked'
                                        : 'Assistant'}
                                </p>
                                {message.role === 'user' ? (
                                    <p>
                                        {/^confirm [a-f0-9]{12}(?: visibility (?:only_me|friends|everyone))?$/i.test(
                                            message.content,
                                        )
                                            ? 'Confirm list change'
                                            : message.content}
                                    </p>
                                ) : (
                                    <CollectionAnswer
                                        content={message.content}
                                        active={index === messages.length - 1}
                                        busy={busy}
                                        onReply={(text) => void reply(text)}
                                        reportedQuestionIds={
                                            reportedQuestionIds
                                        }
                                    />
                                )}
                                {message.error && message.error !== error && (
                                    <p className="ask-card-note">
                                        This answer was interrupted. You can ask
                                        again.
                                    </p>
                                )}
                                {message.status === 'cancelled' && (
                                    <p>
                                        Stopped. You can ask another question.
                                    </p>
                                )}
                            </article>
                        ))}
                        {busy && (
                            <article
                                ref={pendingMessage}
                                className="ask-message ask-message-assistant ask-working"
                                role="status"
                            >
                                <span
                                    className="ask-loading-dots"
                                    aria-hidden="true"
                                >
                                    <i />
                                    <i />
                                    <i />
                                </span>
                                Looking through your collection…
                                <button
                                    type="button"
                                    onClick={() => void cancel()}
                                >
                                    Stop
                                </button>
                            </article>
                        )}
                    </section>
                )}
                <div ref={composer} className="ask-composer">
                    {!!messages.length && (
                        <Link
                            className="ask-new-conversation"
                            href="/ask?new=1"
                            preserveState={false}
                        >
                            <Plus size={18} aria-hidden="true" />
                            <span>New conversation</span>
                        </Link>
                    )}
                    <form className="ask-form" onSubmit={ask} aria-busy={busy}>
                        <label
                            htmlFor="collection-question"
                            className="sr-only"
                        >
                            Ask your collection
                        </label>
                        <div className="ask-input-row">
                            <textarea
                                ref={input}
                                id="collection-question"
                                rows={1}
                                maxLength={300}
                                value={question}
                                onChange={(event) =>
                                    setQuestion(event.target.value)
                                }
                                placeholder={
                                    messages.length
                                        ? 'Ask a follow-up…'
                                        : 'Ask about your collection…'
                                }
                                required
                                onKeyDown={(event) => {
                                    if (
                                        event.key === 'Enter' &&
                                        !event.shiftKey &&
                                        !event.nativeEvent.isComposing
                                    ) {
                                        event.preventDefault();
                                        event.currentTarget.form?.requestSubmit();
                                    }
                                }}
                            />
                            <button
                                type="submit"
                                className="button"
                                aria-label="Ask question"
                                disabled={busy || !question.trim()}
                            >
                                {busy ? (
                                    <LoaderCircle
                                        className="ask-spinner"
                                        size={20}
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <ArrowUp size={20} aria-hidden="true" />
                                )}
                                <span>Ask</span>
                            </button>
                        </div>
                    </form>
                    <div
                        role="status"
                        aria-live="polite"
                        className="ask-status"
                    >
                        {!busy &&
                            !error &&
                            lastMessage?.role === 'assistant' &&
                            lastMessage.status === 'complete' && (
                                <span className="sr-only">Answer ready.</span>
                            )}
                        {error && (
                            <p role="alert" className="ask-error">
                                Couldn’t finish this question.
                                {lastQuestion && (
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={() => {
                                            setQuestion(lastQuestion);
                                            clearError();
                                            input.current?.focus();
                                        }}
                                    >
                                        Edit question
                                    </button>
                                )}
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
