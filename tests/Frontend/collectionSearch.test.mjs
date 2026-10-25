import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { registerHooks } from 'node:module';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { App } from '@inertiajs/react';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import ts from 'typescript';

registerHooks({
    resolve(specifier, context, nextResolve) {
        const converse = {
            '@laravel/converse': 'converse',
            '@laravel/converse-react': 'react',
        }[specifier];
        const url = converse
            ? new URL(
                  `../../vendor/laravel/converse/packages/${converse}/src/index`,
                  import.meta.url,
              )
            : specifier.startsWith('@/')
              ? new URL(
                    `../../resources/js/${specifier.slice(2)}`,
                    import.meta.url,
                )
              : specifier.startsWith('.')
                ? new URL(specifier, context.parentURL)
                : null;

        if (url) {
            for (const extension of ['.ts', '.tsx', '/index.ts']) {
                if (existsSync(fileURLToPath(url) + extension)) {
                    return nextResolve(url.href + extension, context);
                }
            }
        }

        return nextResolve(specifier, context);
    },
    load(url, context, nextLoad) {
        if (url.endsWith('.css')) {
            return { format: 'module', shortCircuit: true, source: '' };
        }
        if (url.endsWith('.ts') || url.endsWith('.tsx')) {
            return {
                format: 'module',
                shortCircuit: true,
                source: ts.transpileModule(readFileSync(new URL(url), 'utf8'), {
                    compilerOptions: {
                        jsx: ts.JsxEmit.ReactJSX,
                        module: ts.ModuleKind.ESNext,
                    },
                }).outputText,
            };
        }

        return nextLoad(url, context);
    },
});

const { CollectionSearchPage } =
    await import('../../resources/js/pages/social/CollectionSearchPage.tsx');

function renderChat(messages, offset) {
    return renderToStaticMarkup(
        createElement(App, {
            initialComponent: CollectionSearchPage,
            initialPage: {
                component: 'social/CollectionSearchPage',
                url: '/ask',
                props: {
                    csrfToken: 'test-token',
                    initialQuestion: '',
                    reportedQuestionIds: [],
                    chat: {
                        threadId: 'fresh-conversation',
                        endpoint: '/converse/collection/web',
                        streamEndpoint: '/converse/collection/web/:thread',
                        transcriptPath: 'chat.messages',
                        capabilities: { streaming: true },
                        messages,
                        offset,
                    },
                },
            },
        }),
    );
}

await test('a fresh conversation welcomes a question without pretending the assistant is answering', () => {
    for (const messages of [[], { data: [] }]) {
        const html = renderChat(messages, 0);
        assert.match(html, /What would you like to find\?/);
        assert.match(html, /aria-busy="false"/);
        assert.doesNotMatch(html, /ask-working|ask-spinner|Thinking/);
    }
});

await test('existing streams still resume with animated dots and an accessible neutral status', () => {
    for (const [messages, offset] of [
        [{ data: [] }, 7],
        [
            {
                data: [
                    {
                        id: 'question-1',
                        role: 'user',
                        content: 'Compare with Alex',
                    },
                ],
            },
            0,
        ],
    ]) {
        const html = renderChat(messages, offset);
        assert.match(html, /aria-busy="true"/);
        assert.match(html, /ask-loading-dots/);
        assert.match(html, /class="sr-only">Thinking…/);
        assert.match(html, />Stop</);
        assert.doesNotMatch(html, /Looking through your collection/);
    }
});
