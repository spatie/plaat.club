import { Link, usePage } from '@inertiajs/react';
import { renderStreamingMarkdown } from '@laravel/converse-react';
import {
    ArrowUpRight,
    Check,
    Disc3,
    ListMusic,
    ThumbsDown,
    X,
} from 'lucide-react';
import { useEffect, useState, type CSSProperties } from 'react';

import { RecordArtwork } from '@/components/records/RecordArtwork';
import { Avatar } from '@/modules/social/Avatar';
import { RecordLink } from '@/modules/social/RecordOverlay';
import type { Person, SocialProps, Visibility } from '@/modules/social/types';
import {
    VisibilityBadge,
    VisibilityPicker,
} from '@/modules/social/VisibilityPicker';

type Release = {
    title: string;
    artist: string;
    year: number | null;
    cover: string;
    coverPreview?: string | null;
    url: string;
    evidence: string[];
    mediaFormats?: string[];
};
type ListCard = {
    publicId: string;
    name: string;
    description: string | null;
    visibility: Visibility;
    count: number;
    covers: string[];
    url: string;
};
type Term = { name: string; mine: number; theirs: number };
type Card =
    | { kind: 'releases'; items: Release[] }
    | {
          kind: 'collectors';
          items: (Person & { count: number; covers: string[] })[];
      }
    | { kind: 'lists'; items: ListCard[] }
    | { kind: 'comparison'; handle: string; artists: Term[]; styles: Term[] }
    | { kind: 'details'; notice: string; seconds?: number; filters?: string[] }
    | { kind: 'feedback'; questionId: string }
    | {
          kind: 'proposal';
          operation: 'create' | 'update' | 'delete';
          list: string;
          newName: string | null;
          description: string | null;
          visibility: Visibility | 'unchanged';
          records: string;
          filters: string[];
          token: string;
          expiresAt: string;
      };

function ComparisonTable({
    label,
    terms,
    handle,
}: {
    label: 'Artist' | 'Style';
    terms: Term[];
    handle: string;
}) {
    if (!terms.length) {
        return <p>None found.</p>;
    }

    return (
        <table className="ask-comparison-table">
            <caption className="ask-card-note">
                Counts are distinct releases in each collection, not shared
                albums or listening frequency.
            </caption>
            <thead>
                <tr>
                    <th scope="col">{label}</th>
                    <th scope="col">You</th>
                    <th scope="col">@{handle}</th>
                </tr>
            </thead>
            <tbody>
                {terms.map((term) => (
                    <tr key={term.name}>
                        <th scope="row">{term.name}</th>
                        <td>{term.mine}</td>
                        <td>{term.theirs}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function Covers({ covers }: { covers: string[] }) {
    return (
        <span className="ask-covers" aria-hidden="true">
            {covers.length ? (
                covers
                    .slice(0, 4)
                    .map((cover) => (
                        <img
                            key={cover}
                            src={cover}
                            alt=""
                            width={80}
                            height={80}
                            loading="lazy"
                        />
                    ))
            ) : (
                <Disc3 size={32} />
            )}
        </span>
    );
}

function Proposal({
    card,
    active,
    busy,
    onReply,
}: {
    card: Extract<Card, { kind: 'proposal' }>;
    active: boolean;
    busy: boolean;
    onReply: (text: string) => void;
}) {
    const [expired, setExpired] = useState(
        () => Date.parse(card.expiresAt) <= Date.now(),
    );
    const [visibility, setVisibility] = useState<Visibility>(
        card.visibility === 'unchanged' ? 'only_me' : card.visibility,
    );
    useEffect(() => {
        const timer = window.setTimeout(
            () => setExpired(true),
            Math.max(0, Date.parse(card.expiresAt) - Date.now()),
        );
        return () => window.clearTimeout(timer);
    }, [card.expiresAt]);
    const available = active && !expired;
    return (
        <section
            className={`ask-proposal${card.operation === 'delete' ? ' is-delete' : ''}`}
            aria-label="Proposed list change"
        >
            <span className="ask-card-kicker">
                <ListMusic size={16} aria-hidden="true" />
                {card.operation} list
            </span>
            <h3>{card.newName ?? card.list}</h3>
            {card.newName && card.newName !== card.list && (
                <p>Rename “{card.list}”</p>
            )}
            {card.description !== null && (
                <p>{card.description || 'Remove description'}</p>
            )}
            <div className="ask-proposal-facts">
                {available &&
                card.operation !== 'delete' &&
                card.visibility !== 'unchanged' ? (
                    <VisibilityPicker
                        label="List visibility"
                        value={visibility}
                        onChange={setVisibility}
                        disabled={busy}
                    />
                ) : (
                    card.visibility !== 'unchanged' && (
                        <VisibilityBadge value={card.visibility} />
                    )
                )}
                {card.records !== 'unchanged' && <span>{card.records}</span>}
            </div>
            {!!card.filters.length && (
                <p className="ask-card-note">{card.filters.join(' · ')}</p>
            )}
            {card.operation === 'delete' && (
                <p className="ask-card-note">
                    Your collection records will not be deleted.
                </p>
            )}
            {!available && (
                <p className="ask-card-note">
                    {expired
                        ? 'Proposal expired. Ask again to make a change.'
                        : 'This proposal is no longer active.'}
                </p>
            )}
            {available && (
                <div className="ask-proposal-actions">
                    <button
                        type="button"
                        className="button"
                        disabled={busy}
                        onClick={() =>
                            onReply(
                                `confirm ${card.token}${card.operation !== 'delete' && visibility !== card.visibility ? ` visibility ${visibility}` : ''}`,
                            )
                        }
                    >
                        <Check size={17} aria-hidden="true" />
                        {card.operation === 'delete'
                            ? 'Confirm deletion'
                            : 'Confirm'}
                    </button>
                    <button
                        type="button"
                        className="ask-secondary"
                        disabled={busy}
                        onClick={() => onReply('cancel')}
                    >
                        <X size={17} aria-hidden="true" />
                        Cancel
                    </button>
                </div>
            )}
        </section>
    );
}

function AnswerFeedback({
    initialReported,
    questionId,
}: {
    initialReported: boolean;
    questionId: string;
}) {
    const { csrfToken } = usePage<SocialProps>().props;
    const [reported, setReported] = useState(initialReported);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    async function toggleFeedback() {
        if (saving) return;

        const wrong = !reported;
        setSaving(true);
        setError('');

        try {
            const response = await fetch(
                `/ask/answers/${encodeURIComponent(questionId)}/feedback`,
                {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ wrong }),
                },
            );
            if (!response.ok || response.redirected) {
                throw new Error('Unavailable');
            }

            setReported(wrong);
        } catch {
            setError('Couldn’t save feedback. Please try again.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="ask-feedback">
            <button
                type="button"
                className="ask-feedback-toggle"
                aria-pressed={reported}
                disabled={saving}
                onClick={() => void toggleFeedback()}
            >
                <ThumbsDown size={14} aria-hidden="true" />
                <span>Wrong answer{reported ? ' · Reported · Undo' : ''}</span>
            </button>
            {error && (
                <p className="ask-feedback-error" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}

function AnswerCard({
    card,
    active,
    busy,
    onReply,
}: {
    card: Exclude<Card, { kind: 'feedback' }>;
    active: boolean;
    busy: boolean;
    onReply: (text: string) => void;
}) {
    switch (card.kind) {
        case 'releases':
            return (
                <div className="ask-release-grid">
                    {card.items.map((record, index) => (
                        <article
                            className="ask-release catalog-record"
                            key={record.url}
                            data-media-format={record.mediaFormats?.[0]}
                            style={
                                {
                                    '--card-index': Math.min(index, 7),
                                } as CSSProperties
                            }
                        >
                            <RecordLink
                                href={record.url}
                                className="ask-release-link"
                            >
                                <span className="ask-artwork catalog-record-open">
                                    <RecordArtwork
                                        cover={record.cover}
                                        coverPreview={
                                            record.coverPreview ?? undefined
                                        }
                                        artist={record.artist}
                                        title={record.title}
                                        mediaFormat={record.mediaFormats?.[0]}
                                    />
                                    <ArrowUpRight
                                        size={22}
                                        aria-hidden="true"
                                    />
                                </span>
                                <span className="ask-release-artist">
                                    {record.artist}
                                </span>
                                <h4>{record.title}</h4>
                                <span className="ask-card-note">
                                    {record.year ?? 'Year unknown'}
                                    {record.mediaFormats?.length
                                        ? ` · ${record.mediaFormats.join(', ')}`
                                        : ''}
                                </span>
                            </RecordLink>
                            {record.evidence
                                .filter((evidence) =>
                                    evidence.includes(
                                        'album match is unverified',
                                    ),
                                )
                                .map((warning) => (
                                    <p key={warning} className="ask-card-note">
                                        {warning}
                                    </p>
                                ))}
                        </article>
                    ))}
                </div>
            );
        case 'collectors':
            return (
                <div className="ask-collectors">
                    {card.items.map((person) => (
                        <div className="ask-collector" key={person.publicId}>
                            <Link
                                href={person.profileUrl}
                                className="ask-person-card"
                            >
                                <Avatar
                                    person={person}
                                    className="ask-avatar"
                                />
                                <span>
                                    <strong>{person.name}</strong>
                                    <span className="ask-card-note">
                                        @{person.handle}
                                    </span>
                                </span>
                                <ArrowUpRight size={18} aria-hidden="true" />
                            </Link>
                            <Link
                                href={person.profileUrl}
                                className="ask-collection-card"
                            >
                                <Covers covers={person.covers} />
                                <span>
                                    <strong>Collection</strong>
                                    <span className="ask-card-note">
                                        {person.count} records
                                    </span>
                                </span>
                                <ArrowUpRight size={18} aria-hidden="true" />
                            </Link>
                        </div>
                    ))}
                </div>
            );
        case 'lists':
            return card.items.length ? (
                <div className="ask-list-grid">
                    {card.items.map((list) => (
                        <Link
                            href={list.url}
                            key={list.publicId}
                            className="ask-list-card"
                        >
                            <Covers covers={list.covers} />
                            <span className="ask-list-info">
                                <span className="ask-card-kicker">
                                    List{' '}
                                    <ArrowUpRight
                                        size={18}
                                        aria-hidden="true"
                                    />
                                </span>
                                <h3>{list.name}</h3>
                                {list.description && <p>{list.description}</p>}
                                <span className="ask-list-meta">
                                    <VisibilityBadge value={list.visibility} />
                                    <span>{list.count} records</span>
                                </span>
                            </span>
                        </Link>
                    ))}
                </div>
            ) : (
                <p className="ask-empty">
                    <ListMusic size={22} aria-hidden="true" />
                    No lists yet. Try “Make a list called Sunday morning”.
                </p>
            );
        case 'comparison':
            return (
                <div className="ask-comparison">
                    <section>
                        <h3>Shared artists</h3>
                        <ComparisonTable
                            label="Artist"
                            terms={card.artists}
                            handle={card.handle}
                        />
                    </section>
                    <details className="ask-comparison-styles">
                        <summary>Shared styles</summary>
                        <ComparisonTable
                            label="Style"
                            terms={card.styles}
                            handle={card.handle}
                        />
                    </details>
                </div>
            );
        case 'details':
            return null;
        case 'proposal':
            return (
                <Proposal
                    card={card}
                    active={active}
                    busy={busy}
                    onReply={onReply}
                />
            );
    }
}

export function CollectionAnswer({
    content,
    active,
    busy,
    onReply,
    reportedQuestionIds,
}: {
    content: string;
    active: boolean;
    busy: boolean;
    onReply: (text: string) => void;
    reportedQuestionIds: string[];
}) {
    // Structured blocks are generated by the local handler, never by the model.
    const parts = content.split(/^```plaat-card\n([\s\S]*?)\n```$/gm);
    return (
        <>
            {parts.map((part, index) => {
                if (index % 2) {
                    try {
                        const card = JSON.parse(part) as Card;
                        if (card.kind === 'feedback') {
                            return (
                                <AnswerFeedback
                                    key={card.questionId}
                                    questionId={card.questionId}
                                    initialReported={reportedQuestionIds.includes(
                                        card.questionId,
                                    )}
                                />
                            );
                        }

                        return (
                            <AnswerCard
                                key={index}
                                card={card}
                                active={active}
                                busy={busy}
                                onReply={onReply}
                            />
                        );
                    } catch {
                        return (
                            <p key={index}>
                                This result could not be displayed. Please ask
                                again.
                            </p>
                        );
                    }
                }
                // The buttons replace the manual confirmation instructions for new proposals.
                const text = parts.some(
                    (part, position) =>
                        position % 2 && part.includes('"kind":"proposal"'),
                )
                    ? part.replace(
                          /Reply \*\*confirm [a-f0-9]{12}\*\*[\s\S]*$/,
                          '',
                      )
                    : part;
                return text.trim() ? (
                    <div
                        key={index}
                        className="ask-markdown"
                        dangerouslySetInnerHTML={{
                            // Converse splits ordered lists at blank lines.
                            __html: renderStreamingMarkdown(text).replaceAll(
                                '</ol><ol>',
                                '',
                            ),
                        }}
                    />
                ) : null;
            })}
        </>
    );
}
