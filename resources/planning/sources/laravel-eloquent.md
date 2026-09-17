# Laravel 13 eloquent

Source: https://github.com/laravel/docs/blob/b94b890362111c44de223e09502c610a9d9f20d8/eloquent.md

Revision: `b94b890362111c44de223e09502c610a9d9f20d8`. Retrieved 2026-09-17.

Selected verbatim excerpts from Laravel 13 documentation. Copyright Taylor Otwell. MIT license, reproduced in `../LARAVEL-LICENSE.md`. This file contains only the named sections, not the complete chapter. Relative documentation links belong to the upstream chapter.

## Introduction

Laravel includes Eloquent, an object-relational mapper (ORM) that makes it enjoyable to interact with your database. When using Eloquent, each database table has a corresponding "Model" that is used to interact with that table. In addition to retrieving records from the database table, Eloquent models allow you to insert, update, and delete records from the table as well.

> [!NOTE]
> Before getting started, be sure to configure a database connection in your application's `config/database.php` configuration file. For more information on configuring your database, check out [the database configuration documentation](/docs/{{version}}/database#configuration).
