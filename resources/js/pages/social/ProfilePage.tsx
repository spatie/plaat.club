import { Head, Link, usePage } from '@inertiajs/react';

import { CollectionTabs } from '@/modules/lists/CollectionTabs';
import { CollectionHeading } from '@/modules/social/CollectionHeading';
import { FriendActions } from '@/modules/social/FriendActions';
import { ProfileShelf } from '@/modules/social/ProfileShelf';
import type { ProfileProps, SocialProps } from '@/modules/social/types';

export function ProfilePage({
    person,
    listCount,
    recordCount,
    records,
    visible,
    friendsOnly,
    friendship,
    groups,
    browse,
}: ProfileProps) {
    const { auth } = usePage<SocialProps>().props;
    const user = auth.user;
    const owner = user?.id === person.id;
    return (
        <>
            <Head title={`${person.name}’s collection`} />
            <CollectionHeading
                title={
                    owner ? 'Your collection' : `${person.name}’s collection`
                }
                user={owner ? user : null}
            >
                {(!owner || recordCount !== null) && (
                    <p>
                        {!owner && `@${person.handle}`}
                        {!owner && recordCount !== null && ' · '}
                        {recordCount !== null &&
                            `${recordCount.toLocaleString('en-US')} ${recordCount === 1 ? 'record' : 'records'}`}
                    </p>
                )}
            </CollectionHeading>
            <CollectionTabs
                active="records"
                person={person}
                listCount={listCount}
            />
            {!visible ? (
                <section className="empty panel">
                    <h2>
                        {friendsOnly
                            ? 'Good records are better with friends.'
                            : 'A private collection'}
                    </h2>
                    <p>
                        {friendsOnly
                            ? 'This collector shares their records with friends. Once your request is accepted, you can explore their collection.'
                            : 'This collector keeps their collection to themselves. Being friends won’t make it visible.'}
                    </p>
                    {listCount > 0 && (
                        <Link
                            className="button"
                            href={`${person.profileUrl}/lists`}
                        >
                            View lists ({listCount})
                        </Link>
                    )}
                    {friendsOnly && user?.verified && (
                        <>
                            {friendship && (
                                <p>
                                    {friendship.requested_by === user.id
                                        ? 'Request sent. Waiting for them to accept.'
                                        : 'They’ve already sent you a friend request.'}
                                </p>
                            )}
                            <FriendActions
                                personId={person.publicId}
                                actions={
                                    !friendship
                                        ? [
                                              {
                                                  value: 'request',
                                                  label: 'Send friend request',
                                              },
                                          ]
                                        : friendship.requested_by === user.id
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
                                            ]
                                }
                            />
                        </>
                    )}
                    {friendsOnly && user && !user.verified && (
                        <Link className="button" href="/verify-email">
                            Verify your email to send a friend request
                        </Link>
                    )}
                </section>
            ) : records && (records.data.length > 0 || browse.query) ? (
                <ProfileShelf
                    key={person.id}
                    records={records}
                    groups={groups}
                    browse={browse}
                />
            ) : (
                <section className="empty panel">
                    <h2>No records in this collection yet.</h2>
                    {owner && (
                        <p>
                            {user?.importedAt
                                ? 'Add records on Discogs, then sync your collection in Settings.'
                                : 'Import your Discogs collection to add your records.'}
                        </p>
                    )}
                    {owner && (
                        <Link
                            className="button"
                            href={user?.importedAt ? '/settings' : '/friends'}
                        >
                            {user?.importedAt
                                ? 'Manage Discogs connection'
                                : 'Import from Discogs'}
                        </Link>
                    )}
                </section>
            )}
        </>
    );
}
