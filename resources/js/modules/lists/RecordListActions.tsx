import { useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ChevronDown, ListPlus, Plus } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

import { FieldError } from '@/modules/social/FieldError';
import type {
    RecordProps,
    SocialProps,
    Visibility,
} from '@/modules/social/types';
import {
    VisibilityBadge,
    VisibilityPicker,
} from '@/modules/social/VisibilityPicker';
import '../../../css/record-lists.css';

export function RecordListActions({
    record,
}: {
    record: RecordProps['record'];
}) {
    const { auth, recordOverlay } = usePage<SocialProps>().props;
    const create = useForm({
        name: '',
        visibility: 'only_me' as Visibility,
        copyId: record.id,
    });
    const membership = useForm({});
    const [creating, setCreating] = useState(false);
    const [message, setMessage] = useState('');
    const details = useRef<HTMLDetailsElement>(null);
    const nameId = useId();
    const visitOptions = {
        async: true,
        preserveScroll: true,
        preserveUrl: true,
        only: [recordOverlay ? 'recordOverlay' : 'record', 'flash', 'errors'],
    };

    useEffect(() => {
        function close(event: MouseEvent) {
            if (!details.current?.contains(event.target as Node))
                details.current?.removeAttribute('open');
        }
        document.addEventListener('click', close, true);
        return () => document.removeEventListener('click', close, true);
    }, []);

    function changeList(listId: string, name: string, checked: boolean) {
        setMessage('');
        membership.clearErrors();
        membership
            .optimistic<SocialProps>((props) => {
                const updatedRecord = {
                    ...record,
                    lists: record.lists.map((entry) =>
                        entry.publicId === listId
                            ? { ...entry, containsRecord: checked }
                            : entry,
                    ),
                };
                return props.recordOverlay
                    ? {
                          recordOverlay: {
                              ...props.recordOverlay,
                              record: updatedRecord,
                          },
                      }
                    : { record: updatedRecord };
            })
            .submit(
                checked ? 'post' : 'delete',
                `/lists/${listId}/records/${record.publicId}`,
                {
                    ...visitOptions,
                    onSuccess: () =>
                        setMessage(
                            checked
                                ? `Added to “${name}”.`
                                : `Removed from “${name}”.`,
                        ),
                    onNetworkError: () => {
                        setMessage(
                            'Couldn’t save this change. Please try again.',
                        );
                        return false;
                    },
                    onHttpException: () => {
                        setMessage(
                            'Couldn’t save this change. Please try again.',
                        );
                        return false;
                    },
                },
            );
    }

    if (!auth.user?.verified) return null;

    return (
        <details
            className="record-list-actions"
            ref={details}
            onToggle={(event) => {
                if (event.target !== event.currentTarget) return;
                if (!event.currentTarget.open) {
                    setCreating(false);
                    return;
                }
                event.currentTarget
                    .querySelector('.record-list-panel')
                    ?.scrollIntoView({ block: 'nearest' });
            }}
            onKeyDown={(event) => {
                if (event.key === 'Escape' && event.currentTarget.open) {
                    event.stopPropagation();
                    event.currentTarget.open = false;
                    event.currentTarget.querySelector('summary')?.focus();
                }
            }}
        >
            <summary>
                <ListPlus size={18} aria-hidden="true" /> Add to list
                <ChevronDown size={15} aria-hidden="true" />
            </summary>
            <div className="record-list-panel">
                {creating ? (
                    <form
                        className="stack"
                        onSubmit={(event) => {
                            event.preventDefault();
                            create.post('/lists', {
                                ...visitOptions,
                                onSuccess: () => {
                                    create.reset('name');
                                    setCreating(false);
                                    details.current
                                        ?.querySelector('summary')
                                        ?.focus();
                                    setMessage(
                                        'List created and record added.',
                                    );
                                },
                            });
                        }}
                    >
                        <div className="record-list-heading">
                            <button
                                type="button"
                                aria-label="Back to lists"
                                disabled={create.processing}
                                onClick={() => {
                                    setCreating(false);
                                    details.current
                                        ?.querySelector('summary')
                                        ?.focus();
                                }}
                            >
                                <ArrowLeft size={18} aria-hidden="true" />
                            </button>
                            <h2>New list</h2>
                        </div>
                        <label htmlFor={nameId}>
                            List name
                            <input
                                id={nameId}
                                name="list_name"
                                required
                                autoFocus
                                maxLength={120}
                                value={create.data.name}
                                onChange={(event) =>
                                    create.setData('name', event.target.value)
                                }
                            />
                        </label>
                        <FieldError
                            id={`${nameId}-error`}
                            message={create.errors.name}
                        />
                        <div className="list-editor-visibility">
                            <span>Visibility</span>
                            <VisibilityPicker
                                disabled={create.processing}
                                error={create.errors.visibility}
                                label="New list visibility"
                                value={create.data.visibility}
                                onChange={(value) =>
                                    create.setData('visibility', value)
                                }
                            />
                        </div>
                        <button className="button" disabled={create.processing}>
                            Create & add
                        </button>
                    </form>
                ) : (
                    <>
                        <div className="record-list-options">
                            {record.lists.length === 0 && <p>No lists yet.</p>}
                            {record.lists.map((list) => (
                                <label key={list.id}>
                                    <input
                                        type="checkbox"
                                        checked={list.containsRecord}
                                        disabled={membership.processing}
                                        onChange={(event) =>
                                            changeList(
                                                list.publicId,
                                                list.name,
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    <span>{list.name}</span>
                                    <VisibilityBadge
                                        value={list.visibility}
                                        iconOnly
                                    />
                                </label>
                            ))}
                        </div>
                        <button
                            className="record-list-new"
                            type="button"
                            disabled={membership.processing}
                            onClick={() => {
                                setMessage('');
                                membership.clearErrors();
                                create.clearErrors();
                                setCreating(true);
                            }}
                        >
                            <Plus size={18} aria-hidden="true" /> New list
                        </button>
                    </>
                )}
                <FieldError
                    id={`${nameId}-membership-error`}
                    message={(membership.errors as { record?: string }).record}
                />
                <p className="record-list-feedback" role="status">
                    {message}
                </p>
            </div>
        </details>
    );
}
