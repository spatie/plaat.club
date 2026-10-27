import { Head, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useRef } from 'react';

import { CollectionTabs } from '@/modules/lists/CollectionTabs';
import { RecordListPreview } from '@/modules/lists/RecordListPreview';
import type { ListPreview } from '@/modules/lists/types';
import { FieldError } from '@/modules/social/FieldError';
import type { Visibility } from '@/modules/social/types';
import { VisibilityPicker } from '@/modules/social/VisibilityPicker';

export function ListsPage({ lists }: { lists: ListPreview[] }) {
    const dialog = useRef<HTMLDialogElement>(null);
    const nameInput = useRef<HTMLInputElement>(null);
    const form = useForm({
        name: '',
        description: '',
        visibility: 'only_me' as Visibility,
    });

    return (
        <>
            <Head title="Your lists" />
            <div className="page-heading list-heading">
                <h1>Your lists</h1>
            </div>
            <CollectionTabs active="lists" listCount={lists.length} />
            <section aria-label="Your lists">
                <div className="section-heading lists-section-heading">
                    <button
                        className="button"
                        type="button"
                        onClick={() => {
                            dialog.current?.showModal();
                            nameInput.current?.focus();
                        }}
                    >
                        <Plus size={18} aria-hidden="true" /> Make a list
                    </button>
                </div>
                {lists.length === 0 ? (
                    <div className="list-empty">
                        <h2>No lists yet</h2>
                    </div>
                ) : (
                    <div className="collection-previews list-cards">
                        {lists.map((list) => (
                            <RecordListPreview key={list.id} list={list} />
                        ))}
                    </div>
                )}
            </section>
            <dialog
                ref={dialog}
                className="list-create-dialog"
                aria-labelledby="new-list-title"
                aria-describedby="new-list-description"
                onCancel={(event) => {
                    if (form.processing) event.preventDefault();
                }}
                onClick={(event) => {
                    if (
                        event.target === event.currentTarget &&
                        !form.processing
                    )
                        dialog.current?.close();
                }}
            >
                <div className="list-create-content stack">
                    <div className="list-create-heading">
                        <h2 id="new-list-title">Make a list</h2>
                        <button
                            className="list-create-close"
                            type="button"
                            aria-label="Close new list"
                            disabled={form.processing}
                            onClick={() => dialog.current?.close()}
                        >
                            <X size={22} aria-hidden="true" />
                        </button>
                    </div>
                    <p id="new-list-description">
                        Name your selection, then pick records from your
                        collection or search Discogs.
                    </p>
                    <form
                        className="stack"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/lists');
                        }}
                    >
                        <label htmlFor="name">
                            List name
                            <input
                                ref={nameInput}
                                id="name"
                                required
                                maxLength={120}
                                placeholder="Sunday morning records"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                aria-invalid={!!form.errors.name}
                                aria-describedby={
                                    form.errors.name ? 'name-error' : undefined
                                }
                            />
                        </label>
                        <FieldError
                            id="name-error"
                            message={form.errors.name}
                        />
                        <label htmlFor="description">
                            <span>
                                A few words <small>Optional</small>
                            </span>
                            <textarea
                                id="description"
                                rows={3}
                                maxLength={2000}
                                aria-invalid={!!form.errors.description}
                                aria-describedby={
                                    form.errors.description
                                        ? 'description-error'
                                        : undefined
                                }
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                        </label>
                        <FieldError
                            id="description-error"
                            message={form.errors.description}
                        />
                        <div className="list-editor-visibility">
                            <span>Visibility</span>
                            <VisibilityPicker
                                disabled={form.processing}
                                error={form.errors.visibility}
                                label="New list visibility"
                                value={form.data.visibility}
                                onChange={(value) =>
                                    form.setData('visibility', value)
                                }
                            />
                        </div>
                        <div className="actions list-create-actions">
                            <button
                                className="text-button"
                                type="button"
                                disabled={form.processing}
                                onClick={() => dialog.current?.close()}
                            >
                                Cancel
                            </button>
                            <button
                                className="button"
                                disabled={form.processing}
                            >
                                {form.processing ? 'Creating…' : 'Create list'}
                            </button>
                        </div>
                    </form>
                </div>
            </dialog>
        </>
    );
}
