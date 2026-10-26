import type { BrowseState } from './browseState';
import type {
    CollectionGroup,
    CollectionMode,
    CollectionRecordSummary,
} from './types';

export function normalizeSearchText(value: string): string {
    return value
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase();
}

const searchTexts = new WeakMap<CollectionRecordSummary, string>();

function searchText(record: CollectionRecordSummary): string {
    let text = searchTexts.get(record);
    if (text === undefined) {
        text = normalizeSearchText(
            [
                record.artist,
                record.title,
                record.label,
                record.originalYear ?? '',
                record.year,
                ...record.genres,
                ...record.styles,
                ...record.trackTitles,
            ].join(' '),
        );
        searchTexts.set(record, text);
    }
    return text;
}

export function searchRecords(
    records: CollectionRecordSummary[],
    query: string,
): CollectionRecordSummary[] {
    const terms = normalizeSearchText(query)
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    if (terms.length === 0) return records;

    return records.filter((record) => {
        const text = searchText(record);
        return terms.every((term) => text.includes(term));
    });
}

export function recordGroups(
    record: CollectionRecordSummary,
    mode: CollectionMode,
): string[] {
    if (mode === 'decade')
        return [
            record.originalYear
                ? `${Math.floor(record.originalYear / 10) * 10}s`
                : 'Unknown year',
        ];
    if (mode === 'genre')
        return record.genres.length ? record.genres : ['Unclassified'];
    if (mode === 'artist') return record.artists;
    return ['Recently added'];
}

export function collectionGroups(
    records: CollectionRecordSummary[],
    mode: CollectionMode,
): CollectionGroup[] {
    const groups = new Map<string, number>();
    for (const record of records) {
        for (const name of new Set(recordGroups(record, mode))) {
            groups.set(name, (groups.get(name) ?? 0) + 1);
        }
    }
    return [...groups]
        .map(([name, count]) => ({ name, count }))
        .sort((first, second) => {
            if (mode === 'decade')
                return (
                    (parseInt(second.name, 10) || 0) -
                    (parseInt(first.name, 10) || 0)
                );
            return first.name.localeCompare(second.name, 'en', {
                sensitivity: 'base',
                numeric: true,
            });
        });
}

export function recordsInGroup(
    records: CollectionRecordSummary[],
    mode: CollectionMode,
    group: string | null,
): CollectionRecordSummary[] {
    if (mode === 'recent' || group === null) return records;
    const matching = records.filter((record) =>
        recordGroups(record, mode).includes(group),
    );
    if (mode === 'artist')
        return matching.sort(
            (first, second) =>
                (second.originalYear ?? 0) - (first.originalYear ?? 0) ||
                (second.originalReleaseDate ?? '').localeCompare(
                    first.originalReleaseDate ?? '',
                ) ||
                first.title.localeCompare(second.title),
        );
    return mode === 'decade'
        ? matching.sort(
              (first, second) =>
                  (second.originalYear ?? 0) - (first.originalYear ?? 0) ||
                  (
                      second.originalReleaseDate ??
                      String(second.originalYear ?? '')
                  ).localeCompare(
                      first.originalReleaseDate ??
                          String(first.originalYear ?? ''),
                  ) ||
                  first.title.localeCompare(second.title),
          )
        : matching;
}

export function recordsByYear(
    records: CollectionRecordSummary[],
): { year: string; records: CollectionRecordSummary[] }[] {
    const years = new Map<string, CollectionRecordSummary[]>();
    for (const record of records) {
        const year = record.originalYear?.toString() ?? 'Unknown year';
        const entries = years.get(year) ?? [];
        entries.push(record);
        years.set(year, entries);
    }
    return [...years]
        .map(([year, entries]) => ({ year, records: entries }))
        .sort(
            (first, second) =>
                (Number(second.year) || 0) - (Number(first.year) || 0),
        );
}

export function recordsByAddedDate(
    records: CollectionRecordSummary[],
): { date: string; records: CollectionRecordSummary[] }[] {
    const calendar = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Brussels',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
    const days = new Map<string, CollectionRecordSummary[]>();
    for (const record of records.toSorted(
        (first, second) =>
            Date.parse(second.addedAt) - Date.parse(first.addedAt) ||
            second.instanceId - first.instanceId,
    )) {
        const date = calendar.format(new Date(record.addedAt));
        const entries = days.get(date) ?? [];
        entries.push(record);
        days.set(date, entries);
    }
    return [...days].map(([date, records]) => ({ date, records }));
}

export function formatReleaseDate(date: string): string {
    if (date.length === 4) return date;
    const [year, month, day] = date.split('-').map(Number);
    return new Intl.DateTimeFormat('en-GB', {
        year: 'numeric',
        month: 'long',
        ...(day ? { day: 'numeric' as const } : {}),
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, day || 1)));
}

export type CollectionView = {
    groups: CollectionGroup[];
    activeGroup: string | null;
    records: CollectionRecordSummary[];
};

export function collectionView(
    records: CollectionRecordSummary[],
    browse: Pick<BrowseState, 'mode' | 'group' | 'query'>,
): CollectionView {
    const found = searchRecords(records, browse.query);
    const groups = collectionGroups(found, browse.mode);
    const activeGroup =
        browse.mode === 'recent'
            ? null
            : (groups.find((group) => group.name === browse.group)?.name ??
              groups[0]?.name ??
              null);
    return {
        groups,
        activeGroup,
        records: recordsInGroup(found, browse.mode, activeGroup),
    };
}
