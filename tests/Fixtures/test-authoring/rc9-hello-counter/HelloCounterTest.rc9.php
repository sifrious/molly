<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 1. A guest visits the home page and sees the text "Hello stranger"
 */
it('1. Guest sees Hello stranger on home page', function () {
    get('/')
        ->assertStatus(200)
        ->assertSee('Hello stranger');
});

/**
 * 2. A guest visiting the home page does not see the Livewire counter increment button
 */
it('2. Guest does not see Increment button on home page', function () {
    get('/')
        ->assertStatus(200)
        ->assertDontSee('Increment');
});

/**
 * 3. When a guest attempts to submit an increment action to the counter endpoint they receive a 302 redirect to the login page
 */
it('3. Guest is redirected to login when posting increment action', function () {
    post('/counter/increment')
        ->assertStatus(302)
        ->assertRedirect('/login');
});

/**
 * 4. A guest can view the login page which contains a form with fields "email" and "password" and a submit button labeled "Login"
 */
it('4. Guest can view login page with email, password fields and Login button', function () {
    get('/login')
        ->assertStatus(200)
        ->assertSee('email')
        ->assertSee('password')
        ->assertSee('Login');
});

/**
 * 5. A user provides valid credentials on the login form and is authenticated, receiving a 302 redirect to the home page
 */
it('5. User can login with valid credentials and is redirected to home', function () {
    $user = User::factory()->create([
        'password' => bcrypt('secret'),
    ]);

    post('/login', [
        'email' => $user->email,
        'password' => 'secret',
    ])->assertStatus(302)
      ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

/**
 * 6. After a successful login the user visits the home page and sees the text "Hello world"
 */
it('6. Authenticated user sees Hello world on home page', function () {
    $user = User::factory()->create([
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($user)
         ->get('/')
         ->assertStatus(200)
         ->assertSee('Hello world');
});

/**
 * 7. After a successful login the user sees the Livewire counter component showing a count of 0 and an enabled "Increment" button
 */
it('7. Authenticated user sees counter at 0 with Increment button', function () {
    $user = User::factory()->create([
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($user)
         ->get('/')
         ->assertStatus(200)
         ->assertSee('0')
         ->assertSee('Increment');
});

/**
 * 8. When the authenticated user clicks the "Increment" button the displayed count increases by one
 */
it('8. Authenticated user increments counter and sees count increase', function () {
    $user = User::factory()->create([
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($user)
         ->post('/counter/increment')
         ->assertStatus(200);

    // After increment the page should now show count 1
    $this->get('/')
         ->assertSee('1');
});

/**
 * 9. An authenticated user can submit the increment action and receives a 200 OK response and the updated count
 */
it('9. Authenticated increment action returns 200 and updated count', function () {
    $user = User::factory()->create([
        'password' => bcrypt('secret'),
    ]);

    $response = $this->actingAs($user)
                     ->post('/counter/increment');

    $response->assertStatus(200);
    // Assuming the response contains the new count
    $response->assertSee('1');
});

/**
 * 10. An authenticated user can click a logout link, receives a 302 redirect to the login page, and is no longer authenticated
 */
it('10. Authenticated user can logout and is redirected to login', function () {
    $user = User::factory()->create([
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($user)
         ->post('/logout')
         ->assertStatus(302)
         ->assertRedirect('/login');

    $this->assertGuest();
});

/**
 * 11. After logout the user (now a guest) visits the home page and again sees "Hello stranger" and the counter is not usable
 */
it('11. After logout guest sees Hello stranger and no Increment button', function () {
    $user = User::factory()->create([
        'password' => bcrypt('secret'),
    ]);

    // Log in then log out
    $this->actingAs($user)
         ->post('/logout');

    // Guest request
    get('/')
        ->assertStatus(200)
        ->assertSee('Hello stranger')
        ->assertDontSee('Increment');
});
