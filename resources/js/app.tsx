import { createInertiaApp } from '@inertiajs/react';
import { createElement, type ComponentType, type ReactNode } from 'react';

void createInertiaApp({
    resolve: async (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx');
        const load = pages[`./pages/${name}.tsx`];
        if (!load) throw new Error(`Unknown page: ${name}`);
        const module = (await load()) as Record<
            string,
            ComponentType & { layout?: (page: ReactNode) => ReactNode }
        >;
        const component = module.default ?? module[name.split('/').pop()!];
        if (name.startsWith('social/')) {
            const { SocialLayout } =
                await import('@/modules/social/SocialLayout');
            component.layout = (page) =>
                createElement(SocialLayout, null, page);
        }
        return component;
    },
    title: (title) => title || 'Plaat Club',
    strictMode: true,
    progress: {
        color: '#4B5563',
    },
});
