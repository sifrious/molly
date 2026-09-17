# Laravel 13 routing

Source: https://github.com/laravel/docs/blob/b94b890362111c44de223e09502c610a9d9f20d8/routing.md

Revision: `b94b890362111c44de223e09502c610a9d9f20d8`. Retrieved 2026-09-17.

Selected verbatim excerpts from Laravel 13 documentation. Copyright Taylor Otwell. MIT license, reproduced in `../LARAVEL-LICENSE.md`. This file contains only the named sections, not the complete chapter. Relative documentation links belong to the upstream chapter.

## Basic Routing

The most basic Laravel routes accept a URI and a closure, providing a very simple and expressive method of defining routes and behavior without complicated routing configuration files:

```php
use Illuminate\Support\Facades\Route;

Route::get('/greeting', function () {
    return 'Hello World';
});
```



### The Default Route Files

All Laravel routes are defined in your route files, which are located in the `routes` directory. These files are automatically loaded by Laravel using the configuration specified in your application's `bootstrap/app.php` file. The `routes/web.php` file defines routes that are for your web interface. These routes are assigned the `web` [middleware group](/docs/{{version}}/middleware#laravels-default-middleware-groups), which provides features like session state and CSRF protection.

For most applications, you will begin by defining routes in your `routes/web.php` file. The routes defined in `routes/web.php` may be accessed by entering the defined route's URL in your browser. For example, you may access the following route by navigating to `http://example.com/user` in your browser:

```php
use App\Http\Controllers\UserController;

Route::get('/user', [UserController::class, 'index']);
```
