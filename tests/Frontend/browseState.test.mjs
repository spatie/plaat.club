import assert from 'node:assert/strict';
import test from 'node:test';
import {
    browseFromHash,
    browseHash,
} from '../../resources/js/components/records/browseState.ts';

await test('share links preserve categories, Unicode, punctuation and searches', () => {
    for (const [mode, group] of [
        ['genre', 'Blues'],
        ['decade', '1990s'],
        ['artist', 'Comité & Friends + #1'],
    ]) {
        const state = {
            mode,
            group,
            query: 'live & rare + 1',
            visibleCount: 25,
        };
        assert.deepEqual(browseFromHash(browseHash(state)), state);
    }
    assert.equal(
        browseHash({
            mode: 'genre',
            group: 'Blues',
            query: '',
            visibleCount: 25,
        }),
        '#genre=Blues',
    );
});

await test('latest additions and search-only links work while unrelated fragments are ignored', () => {
    const recent = { mode: 'recent', group: null, query: '', visibleCount: 25 };
    assert.equal(browseHash(recent), '');
    for (const hash of ['', '#record-123', '#records', '#invalid=value'])
        assert.deepEqual(browseFromHash(hash), recent);
    assert.deepEqual(browseFromHash('#q=Pink+Floyd'), {
        ...recent,
        query: 'Pink Floyd',
    });
    assert.deepEqual(browseFromHash('#artist='), { ...recent, mode: 'artist' });
});
