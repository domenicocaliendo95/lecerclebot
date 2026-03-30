<?php

use App\Http\Controllers\ClassificaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/classifica', [ClassificaController::class, 'index'])->name('classifica');
Route::get('/giocatore/{id}', [ClassificaController::class, 'show'])->name('giocatore');
