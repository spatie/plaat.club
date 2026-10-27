import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, X } from 'lucide-react';

import { ListRecordArtwork } from '@/modules/lists/ListRecordArtwork';
import { RecordOwnership } from '@/modules/lists/RecordOwnership';
import type { PickerRecord } from '@/modules/lists/types';

export function SortableListRecord({
    index,
    onRemove,
    record,
}: {
    index: number;
    onRemove: () => void;
    record: PickerRecord;
}) {
    const {
        attributes,
        isDragging,
        listeners,
        setActivatorNodeRef,
        setNodeRef,
        transform,
        transition,
    } = useSortable({ id: record.entryPublicId ?? record.releaseId });

    return (
        <li
            ref={setNodeRef}
            className={isDragging ? 'is-dragging' : undefined}
            style={{ transform: CSS.Transform.toString(transform), transition }}
        >
            <button
                ref={setActivatorNodeRef}
                className="selection-drag"
                type="button"
                {...attributes}
                {...listeners}
                aria-label={`Reorder ${record.displayTitle} by ${record.artist} (${record.format})`}
            >
                <GripVertical size={20} aria-hidden="true" />
            </button>
            <span className="selection-number">{index + 1}</span>
            <ListRecordArtwork record={record} />
            <div className="picker-record-text">
                <strong>{record.displayTitle}</strong>
                <small>{record.artist}</small>
                <small>
                    {[record.year, record.format, record.country]
                        .filter(Boolean)
                        .join(' · ')}
                </small>
                {record.catalogNumber && <small>{record.catalogNumber}</small>}
                <RecordOwnership {...record} />
            </div>
            <button
                className="selection-remove"
                type="button"
                aria-label={`Remove ${record.displayTitle} by ${record.artist} (${record.format})`}
                onClick={onRemove}
            >
                <X size={17} aria-hidden="true" />
            </button>
        </li>
    );
}
