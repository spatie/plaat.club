import type { CollectionMode } from './types';

export type BrowseState = {
    mode: CollectionMode;
    group: string | null;
    query: string;
    visibleCount: number;
};

export type BrowseUpdate =
    | Partial<BrowseState>
    | ((current: BrowseState) => BrowseState);

export function browseFromHash(hash: string): BrowseState {
    const params = new URLSearchParams(hash.replace(/^#/, ''));
    const mode =
        (['decade', 'genre', 'artist'] as const).find((key) =>
            params.has(key),
        ) ?? 'recent';
    return {
        mode,
        group: mode === 'recent' ? null : params.get(mode) || null,
        query: params.get('q') ?? '',
        visibleCount: 25,
    };
}

export function browseHash(browse: BrowseState): string {
    const params = new URLSearchParams();
    if (browse.mode !== 'recent') params.set(browse.mode, browse.group ?? '');
    if (browse.query) params.set('q', browse.query);
    const fragment = params.toString();
    return fragment ? `#${fragment}` : '';
}
