<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/scanner', 'scanner.index')->name('scanner.dashboard');
