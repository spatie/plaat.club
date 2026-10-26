import type {
    CollectionGroup,
    CollectionMode,
    CollectionRecord,
    CollectionRecordSummary,
} from '@/components/records/types';
import type { ListPreview } from '@/modules/lists/types';

export type Visibility = 'only_me' | 'friends' | 'everyone';

export type Person = {
    id: number;
    publicId: string;
    name: string;
    handle: string;
    profileUrl: string;
    avatarUrl: string | null;
};

export type Viewer = Person & {
    email: string;
    verified: boolean;
    visibility: Visibility;
    legacyOwner: boolean;
    commentsEnabled: boolean;
    digestEnabled: boolean;
    googleConnected: boolean;
    discogsConnected: boolean;
    discogsUsername: string | null;
    importedAt: string | null;
    lastSyncedAt: string | null;
    syncStatus: string | null;
    syncTotal: number | null;
    collectionCount: number;
};

export type SocialProps = {
    [key: string]: unknown;
    auth: { user: Viewer | null };
    adminUrl: string | null;
    impersonating: boolean;
    csrfToken: string;
    googleEnabled: boolean;
    aiSearchEnabled: boolean;
    notificationCount: number;
    demoUsers: { email: string; label: string; url: string }[];
    flash: { status: string | null };
    errors: Record<string, string>;
    recordOverlay: RecordProps | null;
    socialPreview: {
        title: string;
        description: string;
        url: string;
        image: string;
        imageAlt: string;
        type: string;
    };
};

export type Pagination<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type Collector = Person & {
    recordCount: number;
    records: RecordArtwork[];
};

export type RecordArtwork = { cover: string; title: string; url: string };
export type HomeProps = { artwork: RecordArtwork[] };
export type CollectionsProps =
    | { tab: 'collections'; collectors: Pagination<Collector> }
    | { tab: 'lists'; lists: Pagination<ListPreview> };
export type AuthProps = {
    artwork: RecordArtwork[];
    mode: 'login' | 'register' | 'forgot' | 'reset' | 'verify';
    token?: string;
    email?: string;
};
export type ProfileProps = {
    person: Person;
    listCount: number;
    recordCount: number | null;
    visible: boolean;
    friendsOnly: boolean;
    friendship: FriendsProps['connections'][number] | null;
    records: Pagination<
        CollectionRecordSummary & { section: string; key: string }
    > | null;
    groups: CollectionGroup[];
    browse: { mode: CollectionMode; group: string | null; query: string };
};
export type FeedProps = {
    friends: {
        person: Person;
        visible: boolean;
        records: CollectionRecordSummary[];
    }[];
    importRecords: CollectionRecordSummary[];
    collectionCount: number;
};
export type FriendsProps = {
    query: string;
    people: Pagination<Person & { recordCount: number | null }>;
    connections: {
        user_low: number;
        user_high: number;
        requested_by: number;
        accepted_at: string | null;
    }[];
};
export type RecordProps = {
    collectionUrl: string;
    record: {
        id: number;
        publicId: string;
        url: string;
        owner: Person;
        payload: CollectionRecord;
        formats: string[];
        addedLabel: string;
        sharedNote: string | null;
        privateNote?: string | null;
        canEditNotes: boolean;
        lists: {
            id: number;
            publicId: string;
            name: string;
            visibility: Visibility;
            containsRecord: boolean;
        }[];
        commentsEnabled: boolean;
    };
    comments: Pagination<{
        id: number;
        publicId: string;
        body: string;
        user: Person;
        canDelete: boolean;
    }>;
    appreciations: number;
    appreciated: boolean;
};

export type Notifications = {
    notifications: Pagination<{
        id: number;
        actor: Person;
        text: string;
        record: { title: string; artist: string; cover: string } | null;
        url: string;
        unread: boolean;
        canRespond: boolean;
    }>;
    unreadCount: number;
};
