import '../../../css/collection-motion.css';

import {
    useDeferredValue,
    useEffect,
    useImperativeHandle,
    useMemo,
    useRef,
} from 'react';
import type { Ref } from 'react';
import { flushSync } from 'react-dom';

import { animateCollectionChange } from '@/components/records/animateCollectionChange';
import type {
    BrowseState,
    BrowseUpdate,
} from '@/components/records/browseState';
import { collectionView } from '@/components/records/collection';
import { CollectionControls } from '@/components/records/CollectionControls';
import { CollectionIndex } from '@/components/records/CollectionIndex';
import { motionScrollBehavior } from '@/components/records/recordMotion';
import { RecordShelf } from '@/components/records/RecordShelf';
import type {
    CollectionMode,
    CollectionRecordSummary,
} from '@/components/records/types';

export type CollectionBrowserHandle = {
    /** Shows every record by an artist, then moves scroll and focus to the result. */
    showArtist: (artist: string) => void;
};

type CollectionBrowserProps = {
    browseBasePath?: string;
    browse: BrowseState;
    onBrowse: (update: BrowseUpdate) => void;
    onSelect: (record: CollectionRecordSummary, source: HTMLElement) => void;
    records: CollectionRecordSummary[];
    transitionRecordId: number | null;
    ref?: Ref<CollectionBrowserHandle>;
};

function resultsAnnouncement(
    count: number,
    query: string,
    activeGroup: string | null,
): string {
    const records = `${count} ${count === 1 ? 'record' : 'records'}`;
    if (query)
        return `${records} found for “${query}”${activeGroup ? ` in ${activeGroup}` : ''}`;
    return activeGroup ? `${activeGroup}: ${records}` : '';
}

export function CollectionBrowser({
    browseBasePath,
    browse,
    onBrowse,
    onSelect,
    records,
    transitionRecordId,
    ref,
}: CollectionBrowserProps) {
    const browser = useRef<HTMLElement>(null);
    const heading = useRef<HTMLHeadingElement>(null);
    const stopAnimation = useRef<(() => void) | null>(null);
    const query = useDeferredValue(browse.query);
    const view = useMemo(
        () =>
            collectionView(records, {
                mode: browse.mode,
                group: browse.group,
                query,
            }),
        [records, browse.mode, browse.group, query],
    );
    const { groups, activeGroup } = view;

    useEffect(() => () => stopAnimation.current?.(), []);

    useImperativeHandle(ref, () => ({
        showArtist(artist: string) {
            stopAnimation.current?.();
            flushSync(() =>
                onBrowse({
                    mode: 'artist',
                    group: artist,
                    query: '',
                    visibleCount: 25,
                }),
            );
            browser.current?.scrollIntoView({
                behavior: motionScrollBehavior(),
            });
            heading.current?.focus({ preventScroll: true });
        },
    }));

    function scrollToCollection() {
        heading.current?.parentElement?.scrollIntoView({
            behavior: motionScrollBehavior(),
            block: 'start',
        });
    }

    function switchCollection(next: Partial<BrowseState>) {
        stopAnimation.current?.();
        if (
            (next.mode &&
                next.mode === browse.mode &&
                (!next.group || next.group === activeGroup)) ||
            (!next.mode && next.group && next.group === activeGroup)
        ) {
            if (next.group) scrollToCollection();
            return;
        }
        const update = () => {
            flushSync(() => onBrowse({ ...next, visibleCount: 25 }));
            browser.current?.scrollIntoView({
                block: 'start',
                behavior: 'instant',
            });
            if (next.group) scrollToCollection();
        };
        if (!browser.current) {
            update();
            return;
        }
        stopAnimation.current = animateCollectionChange(
            browser.current,
            update,
        );
    }

    function changeQuery(nextQuery: string) {
        stopAnimation.current?.();
        browser.current?.scrollIntoView({
            block: 'start',
            behavior: 'instant',
        });
        onBrowse({ query: nextQuery, group: null, visibleCount: 25 });
    }

    function selectRecord(
        record: CollectionRecordSummary,
        source: HTMLElement,
    ) {
        stopAnimation.current?.();
        onSelect(record, source);
    }

    return (
        <section
            aria-label="Browse record collection"
            className="collection-browser"
            ref={browser}
        >
            <CollectionControls
                mode={browse.mode}
                onMode={(mode: CollectionMode) =>
                    switchCollection({ mode, group: null })
                }
                onQuery={changeQuery}
                query={browse.query}
            />
            <div
                className={`collection-layout ${browse.mode !== 'recent' ? 'has-index' : 'without-index'}`}
            >
                {browse.mode !== 'recent' && (
                    <CollectionIndex
                        browseBasePath={browseBasePath}
                        activeGroup={activeGroup}
                        groups={groups}
                        key={browse.mode}
                        mode={browse.mode}
                        onGroup={(group) => switchCollection({ group })}
                        query={query}
                    />
                )}
                <div className="collection-main">
                    {(activeGroup || query) && (
                        <div className="collection-heading">
                            <h2 ref={heading} tabIndex={-1}>
                                {query
                                    ? `“${query}”${activeGroup ? ` / ${activeGroup}` : ''}`
                                    : activeGroup}
                            </h2>
                        </div>
                    )}
                    <RecordShelf
                        browseBasePath={browseBasePath}
                        onArtist={(artist) =>
                            switchCollection({
                                mode: 'artist',
                                group: artist,
                                query: '',
                            })
                        }
                        groupBy={
                            browse.mode === 'recent'
                                ? 'added'
                                : browse.mode === 'decade'
                                  ? 'year'
                                  : null
                        }
                        showYears={browse.mode === 'artist'}
                        onMore={() =>
                            onBrowse((current) => ({
                                ...current,
                                visibleCount: current.visibleCount + 25,
                            }))
                        }
                        onReset={() => changeQuery('')}
                        onSelect={selectRecord}
                        records={view.records}
                        transitionRecordId={transitionRecordId}
                        visibleCount={browse.visibleCount}
                    />
                </div>
            </div>
            <p className="sr-only" role="status">
                {resultsAnnouncement(view.records.length, query, activeGroup)}
            </p>
        </section>
    );
}
