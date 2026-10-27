import { InfiniteScroll, router, usePage } from '@inertiajs/react';
import { useContext, useEffect, useEffectEvent, useRef, useState } from 'react';
import {
    browseFromHash,
    browseHash,
    type BrowseState,
    type BrowseUpdate,
} from '@/components/records/browseState';
import { CollectionBrowser } from '@/components/records/CollectionBrowser';
import { CollectionControls } from '@/components/records/CollectionControls';
import { CollectionIndex } from '@/components/records/CollectionIndex';
import { formatReleaseDate } from '@/components/records/collection';
import { motionScrollBehavior } from '@/components/records/recordMotion';
import { RecordSleeve } from '@/components/records/RecordSleeve';
import type { CollectionRecordSummary } from '@/components/records/types';
import { RecordRoom } from '@/modules/social/RecordRoom';
import { RecordOverlayContext } from '@/modules/social/RecordOverlay';
import type { ProfileProps, SocialProps } from '@/modules/social/types';

export function ProfileShelf({
    records,
    groups,
    browse,
}: Pick<ProfileProps, 'records' | 'groups' | 'browse'>) {
    const page = usePage<SocialProps>();
    const openRecord = useContext(RecordOverlayContext);
    const wall = useRef<HTMLDivElement>(null);
    const items = useRef<HTMLDivElement>(null);
    const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const cancelSearch = useRef<(() => void) | null>(null);
    const [query, setQuery] = useState(browse.query);
    const [activeGroup, setActiveGroup] = useState<string | null>(browse.group);
    const basePath = page.url.split(/[?#]/)[0];
    const hasIndex = browse.mode !== 'recent' && groups.length > 0;

    useEffect(() => setQuery(browse.query), [browse.query]);
    useEffect(() => {
        const container = wall.current;
        if (!container) return;
        const update = () => {
            const headings = Array.from(
                container.querySelectorAll<HTMLElement>(
                    '[data-collection-section]',
                ),
            );
            const controls = container
                .closest('.record-room')
                ?.querySelector('.collection-controls');
            const headingHeight =
                container
                    .querySelector('.collection-section')
                    ?.getBoundingClientRect().height ?? 0;
            const top =
                (controls?.getBoundingClientRect().bottom ?? 0) +
                headingHeight / 2;
            const heading =
                headings.findLast(
                    (element) => element.getBoundingClientRect().top <= top,
                ) ?? headings[0];
            setActiveGroup(heading?.dataset.collectionSection ?? null);
        };
        window.addEventListener('scroll', update, { passive: true });
        update();
        return () => window.removeEventListener('scroll', update);
    }, [records?.data]);
    useEffect(
        () => () => {
            if (searchTimer.current) clearTimeout(searchTimer.current);
            cancelSearch.current?.();
        },
        [],
    );

    function scrollToGroup(group: string | null, smooth: boolean) {
        const container = wall.current;
        if (!container) return false;
        const heading = Array.from(
            container.querySelectorAll<HTMLElement>(
                '[data-collection-section]',
            ),
        ).find((element) => element.dataset.collectionSection === group);
        if (group && !heading) return false;
        const target = heading ?? container.closest('.profile-record-room');
        target?.scrollIntoView({
            block: 'start',
            behavior: smooth ? motionScrollBehavior() : 'instant',
        });
        heading?.focus({ preventScroll: true });
        return true;
    }

    function browseUrl(next: ProfileProps['browse']) {
        return `${basePath}?${new URLSearchParams({ mode: next.mode, q: next.query, group: next.group ?? '' })}`;
    }

    function visitOptions(next: ProfileProps['browse']) {
        return {
            only:
                next.mode === browse.mode && next.query === browse.query
                    ? ['records', 'browse']
                    : ['records', 'groups', 'browse'],
            reset: ['records'],
            preserveState: true,
            preserveScroll: true,
        };
    }

    function visit(next: ProfileProps['browse']) {
        if (searchTimer.current) clearTimeout(searchTimer.current);
        cancelSearch.current?.();
        router.visit(browseUrl(next), {
            ...visitOptions(next),
            onCancelToken: (token) => {
                cancelSearch.current = token.cancel;
            },
            onSuccess: () =>
                requestAnimationFrame(() => scrollToGroup(next.group, false)),
        });
    }

    const restoreSection = useEffectEvent(() => {
        if (window.location.hash) visit(browseFromHash(window.location.hash));
        else if (browse.group) scrollToGroup(browse.group, false);
    });

    useEffect(() => {
        restoreSection();
    }, []);

    return (
        <RecordRoom>
            <CollectionControls
                mode={browse.mode}
                query={query}
                onMode={(mode) => visit({ mode, query, group: null })}
                onQuery={(value) => {
                    setQuery(value);
                    cancelSearch.current?.();
                    if (searchTimer.current) clearTimeout(searchTimer.current);
                    searchTimer.current = setTimeout(
                        () => visit({ ...browse, query: value, group: null }),
                        250,
                    );
                }}
            />
            <div className={`collection-layout ${hasIndex ? 'has-index' : ''}`}>
                {hasIndex && (
                    <CollectionIndex
                        key={browse.mode}
                        mode={browse.mode as 'artist' | 'genre' | 'decade'}
                        query={query}
                        groups={groups}
                        activeGroup={activeGroup}
                        browseBasePath={basePath}
                        groupHref={(group) =>
                            browseUrl({ ...browse, query, group })
                        }
                        onPrefetchGroup={(group) => {
                            if (
                                query === browse.query &&
                                records?.data.some(
                                    (record) => record.section === group,
                                )
                            )
                                return;
                            const next = { ...browse, query, group };
                            router.prefetch(
                                browseUrl(next),
                                visitOptions(next),
                                {
                                    cacheFor: '15s',
                                },
                            );
                        }}
                        onGroup={(group) => {
                            if (
                                query === browse.query &&
                                scrollToGroup(group, true)
                            ) {
                                router.replace({
                                    url: browseUrl({ ...browse, query, group }),
                                    props: {
                                        ...page.props,
                                        browse: { ...browse, group },
                                    },
                                    preserveState: true,
                                    preserveScroll: true,
                                });
                                return;
                            }
                            visit({ ...browse, query, group });
                        }}
                    />
                )}
                <div
                    className="collection-main collection-stream"
                    ref={wall}
                    role="region"
                    aria-label="Record collection"
                >
                    <InfiniteScroll
                        data="records"
                        preserveUrl
                        buffer={300}
                        itemsElement={items}
                        params={{
                            data: {
                                mode: browse.mode,
                                q: browse.query,
                                group: '',
                            },
                        }}
                        loading={
                            <p className="collection-loading" role="status">
                                <span
                                    className="collection-loading-record"
                                    aria-hidden="true"
                                />
                                <span>More records coming in…</span>
                            </p>
                        }
                    >
                        <div ref={items}>
                            {Array.from(
                                Map.groupBy(
                                    records?.data ?? [],
                                    (record) => record.section,
                                ),
                            ).map(([section, groupRecords]) => (
                                <section
                                    key={section}
                                    data-collection-section={section}
                                    tabIndex={-1}
                                    className="collection-record-group"
                                    aria-label={
                                        browse.mode === 'recent'
                                            ? formatReleaseDate(section)
                                            : section
                                    }
                                >
                                    <h2 className="collection-section">
                                        {browse.mode === 'recent'
                                            ? formatReleaseDate(section)
                                            : section}
                                    </h2>
                                    <div className="catalog-grid">
                                        {groupRecords.map((record) => (
                                            <RecordSleeve
                                                key={record.key}
                                                record={record}
                                                browseBasePath={basePath}
                                                showYear
                                                transitionRecordId={
                                                    page.props.recordOverlay
                                                        ?.record.payload
                                                        .instanceId ?? null
                                                }
                                                onSelect={(source) =>
                                                    openRecord(
                                                        record.shareUrl,
                                                        source,
                                                    )
                                                }
                                                onArtist={(artist) =>
                                                    visit({
                                                        mode: 'artist',
                                                        group: artist,
                                                        query: '',
                                                    })
                                                }
                                            />
                                        ))}
                                    </div>
                                </section>
                            ))}
                        </div>
                    </InfiniteScroll>
                    {records?.data.length === 0 && (
                        <div className="empty-shelf">
                            <h2>No records found.</h2>
                            <button
                                type="button"
                                onClick={() =>
                                    visit({ ...browse, query: '', group: null })
                                }
                            >
                                Clear search
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </RecordRoom>
    );
}

export function ImportedShelf({
    records,
}: {
    records: CollectionRecordSummary[];
}) {
    const page = usePage<SocialProps & { socialBrowse?: BrowseState }>();
    const openRecord = useContext(RecordOverlayContext);
    const basePath = page.url.split(/[?#]/)[0];
    const hash =
        typeof window === 'undefined'
            ? (page.url.split('#')[1] ?? '')
            : window.location.hash;
    const browse =
        page.props.socialBrowse &&
        browseHash(page.props.socialBrowse) === browseHash(browseFromHash(hash))
            ? page.props.socialBrowse
            : browseFromHash(hash);
    function changeBrowse(update: BrowseUpdate) {
        const next =
            typeof update === 'function'
                ? update(browse)
                : { ...browse, ...update };
        const options = {
            url: `${basePath}${browseHash(next)}`,
            props: { ...page.props, socialBrowse: next },
            preserveState: true,
            preserveScroll: true,
        };
        if (
            browse.query !== next.query ||
            browseHash(browse) === browseHash(next)
        )
            router.replace(options);
        else router.push(options);
    }
    return (
        <RecordRoom>
            <CollectionBrowser
                browse={browse}
                browseBasePath={basePath}
                onBrowse={changeBrowse}
                onSelect={(record, source) => {
                    openRecord(record.shareUrl, source);
                }}
                records={records}
                transitionRecordId={
                    page.props.recordOverlay?.record.payload.instanceId ?? null
                }
            />
        </RecordRoom>
    );
}
