<?php

namespace App\Http\Controllers;

use App\Models\Topic;
use App\Support\Pairing;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** QR code to add a topic in the mobile app (and sign it in). */
class PairingController extends Controller
{
    public function store(Request $request, Topic $topic, Pairing $pairing, TwoFactor $qr): JsonResponse
    {
        $this->authorize('view', $topic);

        $link = $pairing->link($topic, $request->user());

        return response()->json([
            'url' => $link['url'],
            'svg' => $qr->qrCodeSvg($link['url']),
            'expires_at' => $link['expires_at']?->toIso8601String(),
            'signs_in' => $link['signs_in'],
        ]);
    }

    /**
     * Page opened by the camera when the app is not installed (or the
     * link is not handled by it): offers to open it in Tocsin.
     */
    public function show(Request $request, string $topic): View
    {
        return view('pairing.show', [
            'topic' => $topic,
            'appLink' => 'tocsin://pair?url='.rawurlencode($request->fullUrl()),
            'signsIn' => $request->filled('code'),
        ]);
    }
}
