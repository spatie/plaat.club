# Laravel Converse

<p align="left">
<a href="https://github.com/laravel/converse/actions/workflows/tests.yml"><img src="https://github.com/laravel/converse/actions/workflows/tests.yml/badge.svg" alt="Build Status"></a>
<a href="https://www.npmjs.com/package/@laravel/converse"><img src="https://img.shields.io/npm/dt/@laravel/converse" alt="Total Downloads"></a>
<a href="https://www.npmjs.com/package/@laravel/converse"><img src="https://img.shields.io/npm/v/@laravel/converse" alt="Latest Stable Version"></a>
<a href="https://www.npmjs.com/package/@laravel/converse"><img src="https://img.shields.io/npm/l/@laravel/converse" alt="License"></a>
</p>

## Introduction

The framework-free core of the [Laravel Converse](https://github.com/laravel/converse) web chat. It holds the transcript, the composer draft and the transport, and is what the Vue and React packages are built on. You won't usually install it yourself:

- [Laravel Converse](https://github.com/laravel/converse#web-chat): the server side, `converse:install`, and the `Converse::chat()` payload
- [@laravel/converse-vue](https://github.com/laravel/converse/tree/0.x/packages/vue#readme): the `<Chat>` component and the `useChat` composable
- [@laravel/converse-react](https://github.com/laravel/converse/tree/0.x/packages/react#readme): the `<Chat>` component and the `useChat` hook

## License

Laravel Converse is open-sourced software licensed under the [MIT license](LICENSE.md).
