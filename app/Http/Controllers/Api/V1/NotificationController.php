<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $q = AppNotification::where('user_id', $request->user()->id);
        return response()->json([
            'unread' => (clone $q)->whereNull('read_at')->count(),
            'data' => $q->orderByDesc('created_at')->limit(30)->get()->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->title, 'body' => $n->body, 'link' => $n->link,
                'read' => $n->read_at !== null, 'created_at' => $n->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function read(Request $request, string $id)
    {
        AppNotification::where('user_id', $request->user()->id)->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->noContent();
    }

    public function readAll(Request $request)
    {
        AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->noContent();
    }
}
