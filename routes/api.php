<?php

use App\Http\Controllers\WhatsAppController;
use App\Http\Middleware\VerifyMetaWebhookSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// ── Public webhook routes (no auth — Meta calls these directly) ─────────────
// Controles P0 de lanzamiento: la ruta POST (entregas reales de Meta) lleva
// throttle por IP + verificación de firma X-Hub-Signature-256. La ruta GET
// (challenge de verificación de Meta) NUNCA lleva firma — Meta no la envía
// ahí — por eso queda intacta, sin ningún middleware nuevo.
Route::prefix('whatsapp')->group(function () {
    Route::get('/webhook/{tenant_token}',  [WhatsAppController::class, 'verify']);
    Route::post('/webhook/{tenant_token}', [WhatsAppController::class, 'handle'])
        ->middleware(['throttle:whatsapp-webhook-ip', VerifyMetaWebhookSignature::class]);
});


