<?php

use App\Http\Controllers\ProductImageController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:products.edit')->group(function () {
    Route::post('/products/{product}/images', [ProductImageController::class, 'store'])->name('products.images.store');
    Route::put('/products/{product}/images/reorder', [ProductImageController::class, 'reorder'])->name('products.images.reorder');
    Route::put('/products/{product}/images/{productImage}/primary', [ProductImageController::class, 'primary'])->name('products.images.primary');
    Route::delete('/products/{product}/images/{productImage}', [ProductImageController::class, 'destroy'])->name('products.images.destroy');
});
