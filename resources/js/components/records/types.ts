export type CollectionTrack = {
    position: string;
    title: string;
    artist: string;
    duration: string;
    appleMusicUrl: string;
    appleMusicSearch: boolean;
    spotifyUrl: string;
    spotifySearch: boolean;
};

export type DiscogsCredit = {
    id?: number;
    name: string;
    anv?: string;
    role?: string;
    tracks?: string;
};

export type ArtistBio = {
    id: number;
    name: string;
    profile: string;
    discogsUrl?: string | null;
};

export type CollectionRecord = {
    id: number;
    publicId?: string;
    instanceId: number;
    artist: string;
    artists: string[];
    title: string;
    displayTitle: string;
    edition: string;
    year: number;
    originalYear: number | null;
    originalReleaseDate?: string | null;
    pressingReleaseDate?: string | null;
    addedAt: string;
    label: string;
    catalogNumber: string;
    format: string;
    mediaFormats?: string[];
    genres: string[];
    styles: string[];
    cover: string;
    coverPreview?: string;
    discogsUrl: string;
    appleMusicUrl: string;
    appleMusicSearch: boolean;
    spotifyUrl: string;
    spotifySearch: boolean;
    bandcampUrl?: string | null;
    bandcampSearch?: boolean;
    artistBios?: ArtistBio[];
    releaseNotes?: string | null;
    credits?: {
        release: DiscogsCredit[];
        tracks: { position: string; title: string; artists: DiscogsCredit[] }[];
    };
    trackSource: 'release' | 'master';
    tracks: CollectionTrack[];
};

export type CollectionMode = 'recent' | 'decade' | 'genre' | 'artist';
export type CollectionGroup = { name: string; count: number };

export type CollectionRecordSummary = Omit<
    CollectionRecord,
    | 'tracks'
    | 'catalogNumber'
    | 'discogsUrl'
    | 'edition'
    | 'pressingReleaseDate'
    | 'trackSource'
> & {
    trackTitles: string[];
    shareUrl: string;
    shareDescription?: string;
    owner?: { name: string; profileUrl: string; avatarUrl: string | null };
};
