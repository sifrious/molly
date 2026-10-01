<?php

use Illuminate\Support\Facades\Route;

Route::get('/ready', fn () => response()->json(['ready' => true]));
