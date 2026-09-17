# Laravel 13 validation

Source: https://github.com/laravel/docs/blob/b94b890362111c44de223e09502c610a9d9f20d8/validation.md

Revision: `b94b890362111c44de223e09502c610a9d9f20d8`. Retrieved 2026-09-17.

Selected verbatim excerpts from Laravel 13 documentation. Copyright Taylor Otwell. MIT license, reproduced in `../LARAVEL-LICENSE.md`. This file contains only the named sections, not the complete chapter. Relative documentation links belong to the upstream chapter.

## Introduction

Laravel provides several different approaches to validate your application's incoming data. It is most common to use the `validate` method available on all incoming HTTP requests. However, we will discuss other approaches to validation as well.

Laravel includes a wide variety of convenient validation rules that you may apply to data, even providing the ability to validate if values are unique in a given database table. We'll cover each of these validation rules in detail so that you are familiar with all of Laravel's validation features.



### Writing the Validation Logic

Now we are ready to fill in our `store` method with the logic to validate the new blog post. To do this, we will use the `validate` method provided by the `Illuminate\Http\Request` object. If the validation rules pass, your code will keep executing normally; however, if validation fails, an `Illuminate\Validation\ValidationException` exception will be thrown and the proper error response will automatically be sent back to the user.

If validation fails during a traditional HTTP request, a redirect response to the previous URL will be generated. If the incoming request is an XHR request, a [JSON response containing the validation error messages](#validation-error-response-format) will be returned.

To get a better understanding of the `validate` method, let's jump back into the `store` method:

```php
/**
 * Store a new blog post.
 */
public function store(Request $request): RedirectResponse
{
    $validated = $request->validate([
        'title' => ['required', 'unique:posts', 'max:255'],
        'body' => ['required'],
    ]);

    // The blog post is valid...

    return redirect('/posts');
}
```

As you can see, the validation rules are passed into the `validate` method. Don't worry - all available validation rules are [documented](#available-validation-rules). Again, if the validation fails, the proper response will automatically be generated. If the validation passes, our controller will continue executing normally.

In addition, you may use the `validateWithBag` method to validate a request and store any error messages within a [named error bag](#named-error-bags):

```php
$validated = $request->validateWithBag('post', [
    'title' => ['required', 'unique:posts', 'max:255'],
    'body' => ['required'],
]);
```
