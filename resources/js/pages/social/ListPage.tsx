import { Head, Link, router } from '@inertiajs/react';
import { Share2 } from 'lucide-react';
import { useState } from 'react';

import { ListRecordCard } from '@/modules/lists/ListRecordCard';
import { ListSharing } from '@/modules/lists/ListSharing';
import type { ListProps } from '@/modules/lists/types';
import { VisibilityBadge } from '@/modules/social/VisibilityPicker';
import '../../../css/record-lists.css';

export function ListPage({ list, records }: ListProps) {
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [shareStatus, setShareStatus] = useState<'copied' | 'manual' | null>(
        null,
    );

    async function shareList() {
        setShareStatus(null);
        try {
            if (navigator.share) {
                await navigator.share({ title: list.name, url: list.url });
                return;
            }
            await navigator.clipboard.writeText(list.url);
            setShareStatus('copied');
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError')
                return;
            setShareStatus('manual');
        }
    }

    return (
        <>
            <Head title={`${list.name} · ${list.owner.name}`} />
            <div className="page-heading list-heading">
                <Link
                    href={
                        list.canEdit
                            ? '/lists'
                            : `${list.owner.profileUrl}/lists`
                    }
                >
                    ←{' '}
                    {list.canEdit ? 'Your lists' : `${list.owner.name}’s lists`}
                </Link>
                <h1>{list.name}</h1>
                {list.description && (
                    <p className="lede list-description">{list.description}</p>
                )}
                <div className="actions">
                    <VisibilityBadge value={list.visibility} />
                    <span>
                        {records.length}{' '}
                        {records.length === 1 ? 'record' : 'records'}
                    </span>
                    {list.visibility !== 'only_me' && (
                        <button
                            className="button secondary"
                            type="button"
                            onClick={() => void shareList()}
                        >
                            <Share2 size={18} aria-hidden="true" /> Share list
                        </button>
                    )}
                    {list.canEdit && (
                        <Link
                            className="button secondary"
                            href={`/lists/${list.publicId}/edit`}
                        >
                            Edit list & pick records
                        </Link>
                    )}
                </div>
                {list.visibility === 'friends' && (
                    <p className="muted">
                        Only the list owner’s accepted friends can open this
                        link.
                    </p>
                )}
                <p role="status" className="list-share-status">
                    {shareStatus === 'copied' && 'Link copied.'}
                </p>
                {shareStatus === 'manual' && (
                    <label className="list-share-link">
                        Copy this link to share the list
                        <input
                            readOnly
                            value={list.url}
                            onFocus={(event) => event.currentTarget.select()}
                        />
                    </label>
                )}
            </div>
            {list.removedCount > 0 && (
                <p className="notice">
                    {list.removedCount}{' '}
                    {list.removedCount === 1 ? 'record was' : 'records were'}{' '}
                    removed from your Discogs collection and no longer appear
                    here. Edit and save the list to acknowledge.
                </p>
            )}
            <div
                className={`list-content ${list.canEdit ? 'with-sharing' : ''}`}
            >
                <section aria-label="Records in this list">
                    {records.length === 0 ? (
                        <div className="list-empty">
                            <h2>A little room for something good.</h2>
                            <p>
                                {list.canEdit
                                    ? 'Pick records from your collection or search Discogs. You don’t need to own them yet.'
                                    : 'No records in this selection yet.'}
                            </p>
                            {list.canEdit && (
                                <Link
                                    className="button"
                                    href={`/lists/${list.publicId}/edit`}
                                >
                                    Pick records
                                </Link>
                            )}
                        </div>
                    ) : (
                        <ol className="list-records">
                            {records.map((record, index) => (
                                <ListRecordCard
                                    key={record.entryPublicId}
                                    listId={list.publicId}
                                    record={record}
                                    number={index + 1}
                                    canEdit={list.canEdit}
                                />
                            ))}
                        </ol>
                    )}
                </section>
                {list.canEdit && (
                    <aside>
                        <ListSharing key={list.id} list={list} />
                        <div className="list-delete">
                            {!confirmDelete ? (
                                <button
                                    className="text-button"
                                    type="button"
                                    onClick={() => setConfirmDelete(true)}
                                >
                                    Delete this list
                                </button>
                            ) : (
                                <div className="stack">
                                    <p>
                                        Delete “{list.name}”? Your records stay
                                        in your collection. This can’t be
                                        undone.
                                    </p>
                                    <div className="actions">
                                        <button
                                            className="button secondary"
                                            disabled={deleting}
                                            type="button"
                                            onClick={() =>
                                                setConfirmDelete(false)
                                            }
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            className="button"
                                            disabled={deleting}
                                            type="button"
                                            onClick={() => {
                                                setDeleting(true);
                                                router.delete(
                                                    `/lists/${list.publicId}`,
                                                    {
                                                        onFinish: () =>
                                                            setDeleting(false),
                                                    },
                                                );
                                            }}
                                        >
                                            Delete list
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </aside>
                )}
            </div>
        </>
    );
}
