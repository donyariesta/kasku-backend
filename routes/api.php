<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FundsAccountController;
use App\Http\Controllers\Api\FundsTransferController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\IuranController;
use App\Http\Controllers\Api\JobMonitorController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TypeController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware('api.auth')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('api.auth')->group(function (): void {
    Route::get('tenants', [TenantController::class, 'index']);
    Route::post('tenants', [TenantController::class, 'store']);

    Route::get('groups', [GroupController::class, 'index']);
    Route::post('groups', [GroupController::class, 'store']);
    Route::delete('groups/{group}', [GroupController::class, 'destroy']);

    Route::get('members', [MemberController::class, 'index']);
    Route::post('members', [MemberController::class, 'store']);
    Route::put('members/{member}', [MemberController::class, 'update']);
    Route::delete('members/{member}', [MemberController::class, 'destroy']);

    Route::get('iuran', [IuranController::class, 'index']);

    Route::get('payments', [PaymentController::class, 'index']);
    Route::post('payments', [PaymentController::class, 'store']);
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy']);

    Route::get('expenses', [ExpenseController::class, 'index']);
    Route::post('expenses', [ExpenseController::class, 'store']);
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy']);

    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::put('users/{user}/role', [UserController::class, 'updateRole']);

    Route::get('funds-accounts', [FundsAccountController::class, 'index']);
    Route::post('funds-accounts', [FundsAccountController::class, 'store']);
    Route::put('funds-accounts/{fundsAccount}', [FundsAccountController::class, 'update']);
    Route::delete('funds-accounts/{fundsAccount}', [FundsAccountController::class, 'destroy']);

    Route::get('funds-transfers', [FundsTransferController::class, 'index']);
    Route::post('funds-transfers', [FundsTransferController::class, 'store']);
    Route::delete('funds-transfers/{fundsTransfer}', [FundsTransferController::class, 'destroy']);

    Route::get('types', [TypeController::class, 'index']);
    Route::post('types', [TypeController::class, 'store']);
    Route::put('types/{type}', [TypeController::class, 'update']);
    Route::delete('types/{type}', [TypeController::class, 'destroy']);

    Route::get('settings', [SettingController::class, 'index']);
    Route::get('settings/{fieldId}', [SettingController::class, 'show']);
    Route::post('settings/upsert', [SettingController::class, 'upsert']);

    Route::prefix('admin/jobs')->group(function (): void {
        Route::get('/', [JobMonitorController::class, 'overview']);
        Route::get('runs', [JobMonitorController::class, 'runs']);
        Route::post('run', [JobMonitorController::class, 'run']);
    });
});
