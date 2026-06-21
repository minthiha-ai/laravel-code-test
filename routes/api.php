<?php

use App\Http\Controllers\ExportEmployeesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function () {
    // Stream all employees to an .xlsx download. GraphQL cannot return a binary
    // body, so export is a Passport-guarded REST endpoint.
    Route::get('/employees/export', ExportEmployeesController::class);
});
