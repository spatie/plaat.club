import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { Avatar } from '@/modules/social/Avatar';
import { FriendActions } from '@/modules/social/FriendActions';
import { Pagination } from '@/modules/social/Pagination';
import type { FriendsProps, Person, SocialProps } from '@/modules/social/types';

export function FriendsPage({ connections, people, query }: FriendsProps) {
    const user = usePage<SocialProps>().props.auth.user!;
    const search = useForm({ q: query });
    const unfriend = useForm({ action: 'unfriend' });
    const dialog = useRef<HTMLDialogElement>(null);
    const stayButton = useRef<HTMLButtonElement>(null);
    const trigger = useRef<HTMLButtonElement | null>(null);
    const [selected, setSelected] = useState<Person | null>(null);
    const entries = people.data.map((person) => ({
        person,
        connection: connections.find(
            (friend) =>
                friend.user_low === person.id || friend.user_high === person.id,
        ),
    }));
    const groups = [
        {
            title: 'Your friends',
            entries: entries.filter(
                ({ connection }) => connection?.accepted_at,
            ),
        },
        {
            title: 'Incoming requests',
            entries: entries.filter(
                ({ connection }) =>
                    connection &&
                    !connection.accepted_at &&
                    connection.requested_by !== user.id,
            ),
        },
        {
            title: 'Sent requests',
            entries: entries.filter(
                ({ connection }) =>
                    connection &&
                    !connection.accepted_at &&
                    connection.requested_by === user.id,
            ),
        },
        {
            title: 'Search results',
            entries: entries.filter(({ connection }) => !connection),
        },
    ];

    function closeDialog() {
        dialog.current?.close();
    }

    return (
        <>
            <Head title="Your friends" />
            <div className="page-heading">
                <h1>Your people.</h1>
                <Link className="button" href="/friends/invite">
                    Invite a friend
                </Link>
            </div>
            <form
                className="search"
                onSubmit={(event) => {
                    event.preventDefault();
                    search.get('/friends/manage');
                }}
            >
                <label htmlFor="find-handle">Find someone by username</label>
                <div className="actions">
                    <input
                        id="find-handle"
                        name="q"
                        value={search.data.q}
                        onChange={(event) =>
                            search.setData('q', event.target.value)
                        }
                        maxLength={31}
                        placeholder="e.g. robin"
                    />
                    <button className="button" disabled={search.processing}>
                        Search
                    </button>
                    {query && <Link href="/friends/manage">Clear search</Link>}
                </div>
            </form>
            {groups
                .filter((group) => group.entries.length)
                .map((group) => (
                    <section
                        className="friends-section"
                        key={group.title}
                        aria-label={group.title}
                    >
                        <h2>{group.title}</h2>
                        <div className="people-list">
                            {group.entries.map(({ person, connection }) => {
                                const actions = !connection
                                    ? [
                                          {
                                              value: 'request',
                                              label: 'Send friend request',
                                          },
                                      ]
                                    : connection.accepted_at
                                      ? [
                                            {
                                                value: 'unfriend',
                                                label: 'Unfriend',
                                            },
                                        ]
                                      : connection.requested_by === user.id
                                        ? [
                                              {
                                                  value: 'cancel',
                                                  label: 'Cancel request',
                                              },
                                          ]
                                        : [
                                              {
                                                  value: 'accept',
                                                  label: 'Accept',
                                              },
                                              {
                                                  value: 'decline',
                                                  label: 'Decline',
                                              },
                                          ];
                                return (
                                    <article
                                        className="person-row"
                                        key={person.id}
                                    >
                                        <div className="person-identity">
                                            <Avatar
                                                className="person-avatar"
                                                person={person}
                                            />
                                            <div>
                                                <h2>
                                                    <Link
                                                        href={person.profileUrl}
                                                    >
                                                        {person.name}
                                                    </Link>
                                                </h2>
                                                <p>
                                                    @{person.handle}
                                                    {person.recordCount !==
                                                        null && (
                                                        <>
                                                            {' · '}
                                                            {person.recordCount.toLocaleString()}{' '}
                                                            {person.recordCount ===
                                                            1
                                                                ? 'item'
                                                                : 'items'}
                                                        </>
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="actions">
                                            {connection?.accepted_at && (
                                                <Link
                                                    className="button secondary"
                                                    href={person.profileUrl}
                                                >
                                                    View collection
                                                </Link>
                                            )}
                                            {connection?.accepted_at ? (
                                                <button
                                                    className="text-button"
                                                    onClick={(event) => {
                                                        trigger.current =
                                                            event.currentTarget;
                                                        setSelected(person);
                                                        unfriend.clearErrors();
                                                        dialog.current?.showModal();
                                                        stayButton.current?.focus();
                                                    }}
                                                >
                                                    Unfriend
                                                    <span className="sr-only">
                                                        {' '}
                                                        {person.name}
                                                    </span>
                                                </button>
                                            ) : (
                                                <FriendActions
                                                    personId={person.publicId}
                                                    actions={actions}
                                                />
                                            )}
                                        </div>
                                    </article>
                                );
                            })}
                        </div>
                    </section>
                ))}
            {!people.data.length && (
                <section className="empty panel">
                    <h2>
                        {query
                            ? 'No exact username match'
                            : 'Your first friend starts here'}
                    </h2>
                    <p>
                        {query
                            ? 'Check the spelling and try the exact username.'
                            : 'Search above, or browse a public collection to meet its owner.'}
                    </p>
                    <Link href="/collections">Browse public collections</Link>
                </section>
            )}
            <Pagination {...people} />
            <dialog
                ref={dialog}
                className="unfriend-dialog"
                aria-labelledby="unfriend-title"
                aria-describedby="unfriend-description"
                onClose={() => {
                    setSelected(null);
                    if (trigger.current?.isConnected) trigger.current.focus();
                }}
            >
                <div className="unfriend-poster">
                    <button
                        type="button"
                        className="unfriend-close"
                        aria-label="Close confirmation"
                        onClick={closeDialog}
                    >
                        ×
                    </button>
                    <div className="unfriend-intro">
                        <p className="page-eyebrow">
                            Plaat Club / A change of tune
                        </p>
                        <h2 id="unfriend-title">
                            Taking a<br />
                            different
                            <br />
                            spin?
                        </h2>
                        <div className="unfriend-vinyl" aria-hidden="true">
                            <span />
                            <span />
                        </div>
                    </div>
                    <form
                        className="unfriend-sheet"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (!selected || unfriend.processing) return;
                            unfriend.post(`/friends/${selected.publicId}`, {
                                preserveScroll: true,
                                onSuccess: closeDialog,
                            });
                        }}
                    >
                        <p className="page-eyebrow">
                            Before you flip the record
                        </p>
                        <h3>Unfriend {selected?.name}?</h3>
                        <p id="unfriend-description">
                            You’ll no longer be friends. You and{' '}
                            {selected?.name} will lose access to each other’s
                            friends-only collection and records.
                        </p>
                        <p>
                            No hard feelings. You can send a new friend request
                            later.
                        </p>
                        {Object.keys(unfriend.errors).length > 0 && (
                            <div className="errors" role="alert">
                                {Object.values(unfriend.errors).map(
                                    (error, index) => (
                                        <p key={index}>{error}</p>
                                    ),
                                )}
                            </div>
                        )}
                        <div className="stack">
                            <button
                                ref={stayButton}
                                type="button"
                                className="button unfriend-stay"
                                onClick={closeDialog}
                            >
                                Stay friends
                            </button>
                            <button
                                className="button unfriend-confirm"
                                disabled={unfriend.processing}
                            >
                                {unfriend.processing
                                    ? 'Unfriending…'
                                    : `Yes, unfriend ${selected?.name ?? ''}`}
                            </button>
                        </div>
                    </form>
                </div>
            </dialog>
        </>
    );
}
