import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    collectionGroups,
    collectionView,
    normalizeSearchText,
    recordsInGroup,
    recordsByAddedDate,
    recordsByYear,
    searchRecords,
} from '../../resources/js/components/records/collection.ts';

const { records: importedRecords } = JSON.parse(
    readFileSync(
        new URL('../../resources/data/collection.json', import.meta.url),
        'utf8',
    ),
);

const records = importedRecords.map((record) => ({
    ...record,
    trackTitles: record.tracks.map((track) => track.title),
}));

await test('latest additions group by Brussels calendar date, newest first, without mutating the collection', () => {
    const additions = [
        { ...records[0], instanceId: 1, addedAt: '2026-06-10T21:59:59Z' },
        { ...records[0], instanceId: 2, addedAt: '2026-06-10T22:00:00Z' },
        { ...records[0], instanceId: 3, addedAt: '2026-06-11T10:00:00Z' },
        { ...records[0], instanceId: 4, addedAt: '2025-11-01T10:00:00Z' },
        { ...records[0], instanceId: 5, addedAt: '2026-06-11T10:00:00Z' },
    ];
    const grouped = recordsByAddedDate(additions);
    assert.deepEqual(
        grouped.map(({ date, records }) => [
            date,
            records.map((record) => record.instanceId),
        ]),
        [
            ['2026-06-11', [5, 3, 2]],
            ['2026-06-10', [1]],
            ['2025-11-01', [4]],
        ],
    );
    assert.deepEqual(
        additions.map((record) => record.instanceId),
        [1, 2, 3, 4, 5],
    );
    assert.deepEqual(recordsByAddedDate([]), []);
});

await test('reissues belong to their original release year', () => {
    const harrison = records[0];
    assert.equal(harrison.year, 2021);
    assert.ok(recordsInGroup(records, 'decade', '1970s').includes(harrison));
    assert.ok(!recordsInGroup(records, 'decade', '2020s').includes(harrison));
    const years = collectionGroups(records, 'decade').map(
        (group) => parseInt(group.name, 10) || 0,
    );
    assert.deepEqual(
        years,
        [...years].sort((first, second) => second - first),
    );
});

await test('a record can belong to several genre and artist collections', () => {
    const collaboration = {
        ...records[0],
        artists: ['George Harrison', 'Eric Clapton'],
        genres: ['Rock', 'Pop', 'Rock'],
    };
    assert.deepEqual(
        recordsInGroup([collaboration], 'artist', 'Eric Clapton'),
        [collaboration],
    );
    assert.deepEqual(collectionGroups([collaboration], 'genre'), [
        { name: 'Pop', count: 1 },
        { name: 'Rock', count: 1 },
    ]);
});

await test('search finds accented artists and combines track names with artist names', () => {
    assert.equal(
        searchRecords(records, 'comite hypnotise')[0].artist,
        'Comité Hypnotisé',
    );
    assert.deepEqual(
        searchRecords(records, 'harrison sweet lord').map(
            (record) => record.id,
        ),
        [19772260],
    );
    assert.equal(
        searchRecords(records, 'COMITÉ Hypnotisé')[0].artist,
        'Comité Hypnotisé',
    );
    assert.equal(searchRecords(records, '  no-such-record-xyz  ').length, 0);
    assert.deepEqual(searchRecords(records, '  '), records);
});

await test('unknown years remain separate from the pressing year', () => {
    const unknown = { ...records[0], originalYear: null };
    assert.deepEqual(collectionGroups([unknown], 'decade'), [
        { name: 'Unknown year', count: 1 },
    ]);
    assert.deepEqual(recordsInGroup([unknown], 'decade', '2020s'), []);
});

await test('imported copies have distinct identities and alternate recordings have distinct listening links', () => {
    assert.equal(new Set(records.map((record) => record.instanceId)).size, 850);
    assert.ok(
        records
            .slice(0, 150)
            .every((record) => record.originalYear && record.tracks.length),
    );
    const first = records[0].tracks.find(
        (track) => track.title === "Isn't It A Pity (Version One)",
    );
    const second = records[0].tracks.find(
        (track) => track.title === "Isn't It A Pity (Version Two)",
    );
    assert.equal(first.appleMusicSearch, false);
    assert.equal(second.appleMusicSearch, false);
    assert.notEqual(first.appleMusicUrl, second.appleMusicUrl);
});

await test('artist records run newest first, with unknown dates last', () => {
    const albums = [1980, null, 1970, 1979, 1969].map((originalYear) => ({
        ...records[0],
        originalYear,
    }));
    assert.deepEqual(
        recordsInGroup(albums, 'artist', records[0].artists[0]).map(
            (album) => album.originalYear,
        ),
        [1980, 1979, 1970, 1969, null],
    );
    assert.deepEqual(
        recordsInGroup(albums, 'decade', '1970s').map(
            (album) => album.originalYear,
        ),
        [1979, 1970],
    );
    assert.deepEqual(
        recordsByYear(albums).map((section) => section.year),
        ['1980', '1979', '1970', '1969', 'Unknown year'],
    );
});

await test('artwork label ink maintains readable contrast', async () => {
    const { contrastingInk } =
        await import('../../resources/js/components/records/artworkLabel.ts');
    for (const color of [
        [246, 35, 146],
        [28, 165, 193],
        [240, 230, 201],
        [31, 39, 39],
    ]) {
        const channels = color
            .map((channel) => channel / 255)
            .map((channel) =>
                channel <= 0.04045
                    ? channel / 12.92
                    : ((channel + 0.055) / 1.055) ** 2.4,
            );
        const luminance =
            channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
        const contrast =
            contrastingInk(...color) === '#ffffff'
                ? 1.05 / (luminance + 0.05)
                : (luminance + 0.05) / 0.05;
        assert.ok(contrast >= 4.5);
    }
});

await test('decades sort original releases by month and day without using the pressing date', () => {
    const dates = ['1970-11-27', '1970-02-10', '1970-02-01', '1970', '1970-02'];
    const albums = dates.map((originalReleaseDate, index) => ({
        ...records[0],
        originalYear: 1970,
        originalReleaseDate,
        pressingReleaseDate: `2021-01-${String(10 - index).padStart(2, '0')}`,
    }));
    assert.deepEqual(
        recordsInGroup(albums, 'decade', '1970s').map(
            (album) => album.originalReleaseDate,
        ),
        ['1970-11-27', '1970-02-10', '1970-02-01', '1970-02', '1970'],
    );
});

await test('search text ignores accents and case', () => {
    assert.equal(normalizeSearchText('Björk SIGUR Rós'), 'bjork sigur ros');
    assert.equal(normalizeSearchText('Ｄｊ'), 'dj');
});

await test('the collection view derives the active group from the requested one', () => {
    const browse = { mode: 'artist', group: null, query: '' };
    const firstArtist = collectionGroups(records, 'artist')[0].name;
    assert.equal(collectionView(records, browse).activeGroup, firstArtist);
    assert.equal(
        collectionView(records, { ...browse, group: 'No Such Artist' })
            .activeGroup,
        firstArtist,
    );

    const harrison = collectionView(records, {
        ...browse,
        group: 'George Harrison',
    });
    assert.equal(harrison.activeGroup, 'George Harrison');
    assert.ok(
        harrison.records.every((record) =>
            record.artists.includes('George Harrison'),
        ),
    );

    const recent = collectionView(records, {
        mode: 'recent',
        group: 'Ignored',
        query: '',
    });
    assert.equal(recent.activeGroup, null);
    assert.equal(recent.records, records);
});

await test('searching narrows the groups and falls back to the first matching group', () => {
    const view = collectionView(records, {
        mode: 'artist',
        group: 'Comité Hypnotisé',
        query: 'harrison sweet lord',
    });
    assert.deepEqual(
        view.groups.map((group) => group.name),
        ['George Harrison'],
    );
    assert.equal(view.activeGroup, 'George Harrison');
    assert.deepEqual(
        view.records.map((record) => record.id),
        [19772260],
    );
});
