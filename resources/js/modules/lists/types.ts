import type { CollectionRecordSummary } from '@/components/records/types';
import type { Pagination, Person, Visibility } from '@/modules/social/types';

export type ListSummary = {
    id: number;
    publicId: string;
    name: string;
    description: string | null;
    visibility: Visibility;
    count: number;
};
export type ListPreview = ListSummary & { covers: string[]; owner: Person };
export type RecordList = Omit<ListSummary, 'count'> & {
    url: string;
    owner: Person;
    canEdit: boolean;
    removedCount: number;
};
export type ListRecord = CollectionRecordSummary & {
    entryPublicId: string;
    releaseId: number;
    catalogNumber?: string;
    discogsUrl?: string;
    copyId?: number;
    copyPublicId?: string;
    owned?: boolean;
    otherPressingOwned?: boolean;
    privateNote?: string | null;
    country?: string;
};
export type PickerRecord = Pick<
    ListRecord,
    | 'releaseId'
    | 'copyId'
    | 'copyPublicId'
    | 'artist'
    | 'displayTitle'
    | 'year'
    | 'originalYear'
    | 'format'
    | 'mediaFormats'
    | 'catalogNumber'
    | 'discogsUrl'
    | 'cover'
    | 'owned'
    | 'otherPressingOwned'
    | 'country'
    | 'label'
> & { entryPublicId?: string };
export type ListProps = { list: RecordList; records: ListRecord[] };
export type ListEditProps = ListProps & {
    query: string;
    source: 'collection' | 'discogs';
    searchError: string | null;
    results: Pagination<PickerRecord>;
};
