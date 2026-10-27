import { RecordArtwork } from '@/components/records/RecordArtwork';
import type { PickerRecord } from '@/modules/lists/types';

export function ListRecordArtwork({ record }: { record: PickerRecord }) {
    return (
        <span
            className="list-record-artwork"
            data-media-format={record.mediaFormats?.[0]}
        >
            <span className="catalog-record-open">
                <RecordArtwork
                    cover={record.cover}
                    artist={record.artist}
                    title={record.displayTitle}
                    mediaFormat={record.mediaFormats?.[0]}
                />
            </span>
        </span>
    );
}
